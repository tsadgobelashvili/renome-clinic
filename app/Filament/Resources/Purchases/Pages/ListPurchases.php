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

    public function mount(): void
    {
        parent::mount();
        $id = session('rs-historical-import.'.auth()->id());
        if ($id) {
            try {
                $this->rsImportProgress = app(RsHistoricalImport::class)->status($id, auth()->user());
                $this->rsImportRun = $id;
            } catch (NotFoundHttpException) {
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
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                            $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                            $this->rsUploadPath = 'purchase-imports/'.$name;

                            return $name;
                        })
                        ->maxSize(10240)->required(),
                ])->action(function (array $data, RsHistoricalImport $importer): void {
                    $storedPath = $data['file'] ?? null;
                    if (! is_string($storedPath) || ! preg_match('~\Apurchase-imports/[a-zA-Z0-9_-]+\.(xlsx|csv)\z~', $storedPath)
                        || $storedPath !== $this->rsUploadPath) {
                        throw ValidationException::withMessages(['file' => __('bank.xlsx_only')]);
                    }
                    try {
                        $path = Storage::disk('local')->path($storedPath);
                        $this->rsImportRun = $importer->start($path, auth()->user());
                        session(['rs-historical-import.'.auth()->id() => $this->rsImportRun]);
                        $this->rsImportProgress = $importer->status($this->rsImportRun, auth()->user());
                        $this->rsImportPaused = false;
                        $this->continueRsImport();
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
