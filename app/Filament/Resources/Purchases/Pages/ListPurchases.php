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
            Action::make('importRs')->label('RS Excel / CSV import')->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('file')->label('RS Excel / CSV ფაილი')->disk('local')->directory('purchase-imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(10240)->required(),
                ])->action(function (array $data, PurchaseImportService $importer): void {
                    $path = Storage::disk('local')->path($data['file']);
                    try {
                        $summary = $importer->import($path, auth()->id());
                        $errors = collect($summary['errors'])->take(5)->implode("\n");
                        Notification::make()->status($summary['errors'] === [] ? 'success' : 'warning')
                            ->title('Import დასრულდა')
                            ->body("Imported: {$summary['imported']} · Duplicates skipped: {$summary['skipped']} · Needs Review: {$summary['needs_review']} · Errors: ".count($summary['errors']).($errors ? "\n{$errors}" : ''))
                            ->persistent()->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
            CreateAction::make()->label('შესყიდვის დამატება'),
        ];
    }
}
