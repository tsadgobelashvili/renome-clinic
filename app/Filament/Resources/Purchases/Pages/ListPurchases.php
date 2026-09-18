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

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('items')->label('შეძენილი პროდუქტები')->url(PurchaseResource::getUrl('items')),
            Action::make('importRs')->label('RS Excel / CSV import')->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('file')->label('RS Excel / CSV ფაილი')->disk('local')->directory('purchase-imports')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(10240)->required(),
                ])->action(function (array $data, PurchaseImportService $importer): void {
                    $path = Storage::disk('local')->path($data['file']);
                    try {
                        $summary = $importer->import($path, auth()->id());
                        $errors = collect($summary['errors'])->take(5)->implode("\n");
                        $this->resetPage();
                        $this->flushCachedTableRecords();
                        Notification::make()->status($summary['errors'] === [] && ($summary['imported'] > 0 || $summary['skipped'] > 0) ? 'success' : 'warning')
                            ->title($summary['imported'] > 0 ? 'RS იმპორტი დასრულდა' : 'ახალი ჩანაწერები არ იმპორტირებულა')
                            ->body("დოკუმენტები: {$summary['documents_imported']} · პროდუქტები: {$summary['imported']} · დუბლიკატები: {$summary['skipped']} · უკატეგორიო: {$summary['needs_review']} · არასწორი სტრიქონები: {$summary['failed_rows']}".($errors ? "\n{$errors}" : ''))
                            ->persistent()->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
            CreateAction::make()->label('შესყიდვის დამატება'),
        ];
    }
}
