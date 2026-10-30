<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Models\PurchaseProduct;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseProducts extends PurchaseProductMappings
{
    public function getTitle(): string
    {
        return __('expense-entry.catalog');
    }

    protected function productQuery(): Builder
    {
        return PurchaseProduct::query();
    }

    public function table(Table $table): Table
    {
        parent::table($table);

        return $table->pushRecordActions([
            Action::make('deleteProduct')->label('პროდუქტის წაშლა')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (PurchaseProduct $record) => Gate::allows('delete', $record))
                ->requiresConfirmation()->modalHeading('პროდუქტის წაშლა')
                ->modalDescription(fn (PurchaseProduct $record) => $record->name.' — წაიშლება მხოლოდ გამოუყენებელი პროდუქტი და მისი ექსკლუზიური ბმები. მიმართულება და ჯგუფი დარჩება.')
                ->action(function (PurchaseProduct $record, Action $action): void {
                    Gate::authorize('delete', $record);
                    try {
                        $record->delete();
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('პროდუქტის წაშლა შეუძლებელია')
                            ->body(collect($exception->errors())->flatten()->implode(' '))->persistent()->send();
                        $action->halt();
                    } catch (QueryException $exception) {
                        report($exception);
                        Notification::make()->danger()->title('პროდუქტის წაშლა ვერ მოხერხდა')
                            ->body('პროდუქტის ბმები შეიცვალა. განაახლეთ გვერდი და გადაამოწმეთ გამოყენების ისტორია.')->send();
                        $action->halt();
                    }
                    unset($this->pendingClassifications[$record->id], $this->classificationOriginals[$record->id]);
                    $this->flushCachedTableRecords();
                    Notification::make()->success()->title('პროდუქტი წაიშალა')->send();
                }),
        ]);
    }
}
