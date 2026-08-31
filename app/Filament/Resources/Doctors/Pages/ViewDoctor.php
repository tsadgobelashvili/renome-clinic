<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Services\DirectExpenseService;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewDoctor extends ViewRecord
{
    protected static string $resource = DoctorResource::class;

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function saveSalaryExpense(
        int $itemId,
        ?int $expenseId,
        mixed $name,
        mixed $amount,
        DirectExpenseService $service,
    ): bool {
        try {
            $service->save($this->unsettledDoctorItem($itemId), $expenseId, $name, $amount);
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

    public function deleteSalaryExpense(int $itemId, int $expenseId, DirectExpenseService $service): void
    {
        try {
            $service->delete($this->unsettledDoctorItem($itemId), $expenseId);
            Notification::make()->success()->title('შენახულია')->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->expenseError('ხარჯი ვერ წაიშალა.');
        }
    }

    public function setOwnerSplitOverride(int $visitId, string $mode): void
    {
        abort_unless($this->record->isOwnerSplitDoctor(), 403);
        abort_unless(in_array($mode, ['auto', 'on', 'off'], true), 422);

        Visit::query()
            ->whereKey($visitId)
            ->where('doctor_id', $this->record->getKey())
            ->whereHas('treatmentCaseItems', fn (Builder $query): Builder => $query->salaryUnsettled())
            ->firstOrFail()
            ->update(['owner_split_override' => $mode === 'auto' ? null : $mode]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    private function unsettledDoctorItem(int $itemId): VisitTreatmentCase
    {
        return VisitTreatmentCase::query()
            ->salaryUnsettled()
            ->whereHas('visit', fn (Builder $query): Builder => $query
                ->where('doctor_id', $this->record->getKey()))
            ->findOrFail($itemId);
    }

    private function expenseError(string $message): void
    {
        Notification::make()->danger()->title('ხარჯი ვერ შეინახა')->body($message)->send();
    }
}
