<?php

namespace App\Filament\Concerns;

use App\Filament\Actions\SalaryAllocationFields;
use App\Models\Doctor;
use App\Models\SalarySettlement;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Services\DirectExpenseService;
use App\Services\DoctorSalaryHistory;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

trait InteractsWithDoctorSalary
{
    public function payIsraeliSalaryAction(): Action
    {
        return Action::make('payIsraeliSalary')->label(__('salary-payout.pay_remaining'))
            ->visible(fn () => auth()->user()?->isOwner() || auth()->user()?->isAdministrator())
            ->model(SalarySettlement::class)
            ->record(fn (array $arguments) => SalarySettlement::query()->where('doctor_id', $this->salaryDoctorId())
                ->where('uses_allocations', true)->with('payouts.allocations')->withSum('payouts as paid_gel', 'total_gel')
                ->findOrFail($arguments['settlement'] ?? 0))
            ->modalHeading(fn (SalarySettlement $record) => $record->doctor->full_name.' — '.__('salary-payout.pay_remaining'))
            ->modalWidth('5xl')->modalSubmitActionLabel(__('salary-payout.confirm'))
            ->extraModalWindowAttributes(['class' => 'renome-israeli-salary-modal'])
            ->schema([
                View::make('filament.resources.doctors.salary-payout-history')->viewData(fn (SalarySettlement $record) => ['settlement' => $record, 'showPayButton' => false]),
                ...SalaryAllocationFields::make(fn ($get, SalarySettlement $record) => (float) $record->salary_total, fn ($record) => $record->paid_gel),
            ])
            ->action(function (SalarySettlement $record, array $data) {
                app(IsraeliSalaryPayoutService::class)->payRemaining($record->id, $data['allocations'], $data['payout_request_key'], auth()->user());
                Notification::make()->success()->title(__('salary-payout.saved'))->send();
            });
    }

    public ?int $activeSalaryDoctorId = null;

    public ?int $salaryHistoryDoctorId = null;

    public function prepareDoctorSalary(int $doctorId): void
    {
        $this->activeSalaryDoctorId = $doctorId;
        $this->salaryHistoryDoctorId = null;
    }

    public function toggleDoctorSalaryHistory(int $doctorId): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);
        abort_unless($this->activeSalaryDoctorId === $doctorId, 403);
        $this->salaryHistoryDoctorId = $this->salaryHistoryDoctorId === $doctorId ? null : $doctorId;
    }

    public function isDoctorSalaryHistoryVisible(int $doctorId): bool
    {
        return $this->salaryHistoryDoctorId === $doctorId;
    }

    /** @return Collection<int, SalarySettlement> */
    public function doctorSalaryHistory(int $doctorId): Collection
    {
        if (! $this->isDoctorSalaryHistoryVisible($doctorId)) {
            return collect();
        }

        return app(DoctorSalaryHistory::class)->forDoctor($doctorId);
    }

    public function undoSettlement(int $settlementId, SalarySettlementService $service): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);
        $doctorId = $this->salaryDoctorId();
        $undone = $service->undo($settlementId, $doctorId);

        if ($undone) {
            Doctor::query()->find($doctorId)?->clearCompensationSummaryCache();
        }

        $this->dispatch('$refresh');
        Notification::make()
            ->status($undone ? 'success' : 'warning')
            ->title($undone ? 'ხელფასის დაფიქსირება გაუქმდა.' : 'ჩანაწერი უკვე გაუქმებულია ან აღარ არსებობს.')
            ->send();
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
            $this->salaryExpenseError((string) collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            report($exception);
            $this->salaryExpenseError('დაფიქსირდა ტექნიკური შეცდომა. ძველი მნიშვნელობა შენარჩუნებულია.');
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
            $this->salaryExpenseError('ხარჯი ვერ წაიშალა.');
        }
    }

    public function setOwnerSplitOverride(int $visitId, string $mode): void
    {
        $doctor = Doctor::query()->findOrFail($this->salaryDoctorId());
        abort_unless($doctor->isOwnerSplitDoctor(), 403);
        abort_unless(in_array($mode, ['auto', 'on', 'off'], true), 422);

        Visit::query()
            ->whereKey($visitId)
            ->where('doctor_id', $doctor->getKey())
            ->whereHas('treatmentCaseItems', fn (Builder $query): Builder => $query->salaryUnsettled())
            ->firstOrFail()
            ->update(['owner_split_override' => $mode === 'auto' ? null : $mode]);
    }

    private function salaryDoctorId(): int
    {
        $doctorId = $this->activeSalaryDoctorId
            ?? (isset($this->record) && $this->record instanceof Doctor ? $this->record->getKey() : null);

        abort_unless($doctorId, 404);

        return (int) $doctorId;
    }

    private function unsettledDoctorItem(int $itemId): VisitTreatmentCase
    {
        return VisitTreatmentCase::query()
            ->salaryUnsettled()
            ->whereHas('visit', fn (Builder $query): Builder => $query->where('doctor_id', $this->salaryDoctorId()))
            ->findOrFail($itemId);
    }

    private function salaryExpenseError(string $message): void
    {
        Notification::make()->danger()->title('ხარჯი ვერ შეინახა')->body($message)->send();
    }
}
