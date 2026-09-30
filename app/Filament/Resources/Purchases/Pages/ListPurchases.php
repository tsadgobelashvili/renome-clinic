<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\PurchaseImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    #[Locked]
    public ?string $rsUploadPath = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('items')->label('შეძენილი პროდუქტები')->url(PurchaseResource::getUrl('items')),
            Action::make('importRs')->label('RS Excel / CSV import')->icon('heroicon-o-arrow-up-tray')
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
                ])->action(function (array $data, PurchaseImportService $importer): void {
                    $storedPath = $data['file'] ?? null;
                    if (! is_string($storedPath) || ! preg_match('~\Apurchase-imports/[a-zA-Z0-9_-]+\.(xlsx|csv)\z~', $storedPath)
                        || $storedPath !== $this->rsUploadPath) {
                        throw ValidationException::withMessages(['file' => __('bank.xlsx_only')]);
                    }
                    try {
                        $path = Storage::disk('local')->path($storedPath);
                        $summary = $importer->import($path, auth()->id());
                        $errors = collect($summary['errors'])->take(5)->implode("\n");
                        $this->resetPage();
                        $this->flushCachedTableRecords();
                        Notification::make()->status($summary['errors'] === [] && ($summary['imported'] > 0 || $summary['skipped'] > 0) ? 'success' : 'warning')
                            ->title($summary['imported'] > 0 ? 'RS იმპორტი დასრულდა' : 'ახალი ჩანაწერები არ იმპორტირებულა')
                            ->body("დოკუმენტები: {$summary['documents_imported']} · პროდუქტები: {$summary['imported']} · დუბლიკატები: {$summary['skipped']} · უკატეგორიო: {$summary['needs_review']} · არასწორი სტრიქონები: {$summary['failed_rows']}".($errors ? "\n{$errors}" : ''))
                            ->persistent()->send();
                    } finally {
                        $this->rsUploadPath = null;
                        Storage::disk('local')->delete($storedPath);
                    }
                }),
            CreateAction::make()->label('შესყიდვის დამატება'),
        ];
    }
}
