<?php

namespace App\Filament\Resources\DirectExpenses\Pages;

use App\Filament\Resources\DirectExpenses\DirectExpenseResource;
use App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable;
use App\Models\VisitTreatmentCase;
use App\Services\DirectExpenseService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListDirectExpenses extends ListRecords
{
    protected static string $resource = DirectExpenseResource::class;

    public function saveExpense(int $itemId, ?int $expenseId, mixed $name, mixed $amount, DirectExpenseService $service): bool
    {
        try {
            $service->save($this->eligibleItem($itemId), $expenseId, $name, $amount);

            Notification::make()->success()->title('შენახულია')->send();

            return true;
        } catch (ValidationException $exception) {
            $this->expenseError((string) collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            report($exception);
            $this->expenseError('დაფიქსირდა ტექნიკური შეცდომა. ძველი მნიშვნელობა შენარჩუნებულია.');
        }

        return false;
    }

    public function deleteExpense(int $itemId, int $expenseId, DirectExpenseService $service): void
    {
        try {
            $service->delete($this->eligibleItem($itemId), $expenseId);
            Notification::make()->success()->title('შენახულია')->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->expenseError('ხარჯი ვერ წაიშალა.');
        }
    }

    private function eligibleItem(int $itemId): VisitTreatmentCase
    {
        return VisitTreatmentCase::query()
            ->whereHas('visit', fn (Builder $query): Builder => $query->where('visit_type', '!=', 'consultation'))
            ->whereHas('treatmentCase', fn (Builder $query): Builder => $query
                ->whereIn('category', DirectExpensesTable::ELIGIBLE_CATEGORIES))
            ->with('visit')
            ->findOrFail($itemId);
    }

    private function expenseError(string $message): void
    {
        Notification::make()->danger()->title('ხარჯი ვერ შეინახა')->body($message)->send();
    }
}
