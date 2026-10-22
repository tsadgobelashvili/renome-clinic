<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\RsHistoricalImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    #[Locked]
    public ?string $rsUploadPath = null;

    #[Locked]
    public ?string $rsImportRun = null;

    #[Locked]
    public array $rsImportProgress = [];

    #[Locked]
    public bool $rsImportPaused = false;

    protected string $rsImportStartStage = 'form_validation';

    protected ?string $preparedRsImport = null;

    public static function rsUploadLimitKiB(): int
    {
        $limits = [10240];
        $temporaryRules = config('livewire.temporary_file_upload.rules') ?? ['max:12288'];
        foreach (is_array($temporaryRules) ? $temporaryRules : explode('|', $temporaryRules) as $rule) {
            if (is_string($rule) && preg_match('/\Amax:(\d+)\z/', $rule, $matches)) {
                $limits[] = (int) $matches[1];
            }
        }
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity(ini_get($setting));
            if ($bytes > 0) {
                // Leave room for the multipart request envelope.
                $limits[] = max(1, intdiv($bytes, 1024) - ($setting === 'post_max_size' ? 64 : 0));
            }
        }

        return min($limits);
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $isImport = $this->getMountedAction()?->getName() === 'importRs';
        $context = ['user_id' => auth()->id(), 'temporary_file_available' => false];
        if ($isImport) {
            foreach ((array) ($this->mountedActions[0]['data']['file'] ?? []) as $file) {
                if ($file instanceof TemporaryUploadedFile) {
                    try {
                        $context += ['original_filename' => $file->getClientOriginalName(), 'extension' => $file->getClientOriginalExtension()];
                        $context['temporary_file_available'] = $file->exists();
                        if ($context['temporary_file_available']) {
                            $context += ['mime' => $file->getMimeType(), 'size' => $file->getSize()];
                        }
                    } catch (\Throwable $exception) {
                        $context['metadata_error'] = $exception->getMessage();
                    }
                }
            }
        }
        try {
            return parent::callMountedAction($arguments);
        } catch (\Throwable $exception) {
            if (! $isImport || $exception instanceof AuthorizationException) {
                throw $exception;
            }
            Log::error('RS import start failed', $context + ['stage' => $this->rsImportStartStage,
                'original_filename' => null, 'extension' => null, 'mime' => null, 'size' => null,
                'exception_class' => $exception::class, 'exception_message' => $exception->getMessage()]);
            Notification::make()->danger()->title($exception instanceof ValidationException || $this->rsImportStartStage === 'form_validation' ? 'ფაილი ვერ აიტვირთა' : 'იმპორტის დაწყება ვერ მოხერხდა')
                ->body('შეამოწმეთ CSV / XLSX ფორმატი და ფაილის ზომა. მაქსიმუმი: '.self::rsUploadLimitKiB().' KiB. სცადეთ ფაილის ხელახლა ატვირთვა.')->persistent()->send();
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            return null;
        }
    }

    public function mount(): void
    {
        parent::mount();
        $id = session('rs-historical-import.'.auth()->id());
        if (PurchaseResource::canCreate()) {
            $id = app(RsHistoricalImport::class)->active(auth()->user()) ?? $id;
        }
        if ($id) {
            try {
                $this->rsImportProgress = app(RsHistoricalImport::class)->status($id, auth()->user());
                $this->rsImportRun = $id;
            } catch (NotFoundHttpException|ModelNotFoundException) {
                session()->forget('rs-historical-import.'.auth()->id());
            }
        }
    }

    public function content(Schema $schema): Schema
    {
        parent::content($schema);

        return $schema->components([View::make('filament.resources.purchases.import-progress'), ...$schema->getComponents()]);
    }

    public function resumeRsImport(): void
    {
        $this->rsImportPaused = false;
        $this->continueRsImport();
    }

    public function continueRsImport(): void
    {
        abort_unless(PurchaseResource::canCreate(), 403);
        if (! $this->rsImportRun || $this->rsImportPaused || ($this->rsImportProgress['phase'] ?? null) === 'completed') {
            return;
        }
        try {
            $this->rsImportProgress = app(RsHistoricalImport::class)->step($this->rsImportRun, auth()->user());
        } catch (\Throwable $exception) {
            report($exception);
            $this->rsImportPaused = true;
            Notification::make()->warning()->title('RS იმპორტი შეჩერებულია')->body('შენახული სტრიქონები დაცულია. გასაგრძელებლად დააჭირეთ „გაგრძელება“.')->persistent()->send();

            return;
        }
        $this->flushCachedTableRecords();
        if ($this->rsImportProgress['phase'] === 'completed') {
            $this->notifyImportComplete($this->rsImportProgress);
        }
    }

    private function notifyImportComplete(array $summary): void
    {
        $errors = collect($summary['errors'])->take(5)->implode("\n");
        Notification::make()->status($summary['errors'] === [] && ($summary['review_rows'] ?? 0) === 0 && ($summary['imported'] > 0 || $summary['skipped'] > 0) ? 'success' : 'warning')
            ->title($summary['imported'] > 0 ? 'RS იმპორტი დასრულდა' : 'ახალი ჩანაწერები არ იმპორტირებულა')
            ->body("დოკუმენტები: {$summary['documents_imported']} · პროდუქტები: {$summary['imported']} · დუბლიკატები: {$summary['skipped']} · უკატეგორიო: {$summary['needs_review']} · არასწორი სტრიქონები: {$summary['failed_rows']}".($errors ? "\n{$errors}" : ''))
            ->persistent()->send();
        if ($summary['errors'] !== [] || ($summary['review_rows'] ?? 0) > 0) {
            Notification::make()->warning()->title('RS იმპორტის სრული შეცდომების ანგარიში')
                ->actions([Action::make('downloadRsErrors')->label('ანგარიშის ჩამოტვირთვა')
                    ->url(route('purchases.import-report', ['report' => $summary['error_report_id']]))->openUrlInNewTab()])
                ->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('items')->label('შეძენილი პროდუქტები')->url(PurchaseResource::getUrl('items')),
            Action::make('importRs')->label('RS Excel / CSV import')->icon('heroicon-o-arrow-up-tray')
                ->disabled(fn () => $this->rsImportRun && ($this->rsImportProgress['phase'] ?? null) !== 'completed')
                ->form([
                    FileUpload::make('file')->label('RS Excel / CSV ფაილი')->disk('local')->directory('purchase-imports')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->rules(['extensions:xlsx,csv'])
                        ->validationMessages(['required' => 'ფაილი ვერ აიტვირთა. აირჩიეთ ფაილი ხელახლა.', 'max' => 'ფაილის ზომა აღემატება დაშვებულ ზღვარს.', 'mimetypes' => 'ფაილის ფორმატი არასწორია.', 'extensions' => 'ფაილის ფორმატი არასწორია.'])
                        ->helperText('CSV / XLSX · მაქსიმუმი: '.self::rsUploadLimitKiB().' KiB.')
                        ->extraAlpineAttributes(['x-init' => "\$el.addEventListener('FilePond:error', () => new FilamentNotification().title('ფაილი ვერ აიტვირთა').body('შეამოწმეთ CSV / XLSX ფორმატი, ფაილის ზომა და კავშირი. მაქსიმუმი: ".self::rsUploadLimitKiB()." KiB.').danger().persistent().send())"])
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                            $this->rsImportStartStage = 'upload_storage';
                            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                            $this->rsUploadPath = 'purchase-imports/'.$name;

                            return $name;
                        })
                        ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): string {
                            $stored = $component->saveUploadedFile($file);
                            if (! is_string($stored)) {
                                throw new \RuntimeException('Unable to save private RS upload.');
                            }
                            try {
                                $this->rsImportStartStage = 'checkpoint_creation_and_source_copy';
                                $this->preparedRsImport = app(RsHistoricalImport::class)->start(Storage::disk('local')->path($stored), auth()->user(),
                                    deferStaging: true, originalFilename: $file->getClientOriginalName());
                            } catch (\Throwable $exception) {
                                Storage::disk('local')->delete($stored);
                                throw $exception;
                            }

                            // Filament deletes the Livewire temporary upload only AFTER
                            // this callback succeeds: checkpoint failures preserve it.
                            return $stored;
                        })
                        ->maxSize(self::rsUploadLimitKiB())->required(),
                ])->action(function (array $data, RsHistoricalImport $importer): void {
                    $storedPath = $data['file'] ?? null;
                    if (! is_string($storedPath) || ! preg_match('~\Apurchase-imports/[a-zA-Z0-9_-]+\.(xlsx|csv)\z~', $storedPath)
                        || $storedPath !== $this->rsUploadPath) {
                        throw ValidationException::withMessages(['mountedActions.0.data.file' => 'ფაილი ვერ აიტვირთა. აირჩიეთ CSV / XLSX ფაილი ხელახლა.']);
                    }
                    try {
                        if (! $this->preparedRsImport) {
                            throw new \RuntimeException('RS checkpoint was not prepared by this upload request.');
                        }
                        $this->rsImportRun = $this->preparedRsImport;
                        $this->rsImportStartStage = 'checkpoint_status';
                        session(['rs-historical-import.'.auth()->id() => $this->rsImportRun]);
                        $this->rsImportProgress = $importer->status($this->rsImportRun, auth()->user());
                        $this->rsImportPaused = false;
                        Notification::make()->success()->title('RS იმპორტი დაიწყო')->body('ფაილი შენახულია. დამუშავების პროგრესი იხილეთ გვერდზე.')->send();
                        $this->resetPage();
                        $this->flushCachedTableRecords();
                    } finally {
                        $this->rsUploadPath = null;
                        Storage::disk('local')->delete($storedPath);
                    }
                }),
            CreateAction::make()->label('შესყიდვის დამატება'),
        ];
    }
}
