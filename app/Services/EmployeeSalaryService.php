<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalarySettlement;
use App\Models\EmployeeSalarySettlementItem;
use App\Models\LabAdditionalWork;
use App\Models\LabCase;
use App\Models\LabMainWork;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryService
{
    public function openingCarry(Employee $employee): float
    {
        return round((float) $employee->salarySettlements()
            ->where('salary_type', 'performance')->where('status', 'confirmed')
            ->latest('settled_at')->latest('id')->value('closing_carry_gel'), 2);
    }

    public function pending(Employee $employee, ?string $from = null, ?string $until = null): Collection
    {
        $this->validatePeriod($from, $until);
        if (! $employee->is_active || ! $employee->salary_active || $employee->salary_type !== 'performance') {
            return collect();
        }

        $rates = $employee->salaryRates()->where('is_active', true)->get()->keyBy('work_type');
        $settled = EmployeeSalarySettlementItem::query()->whereNotNull('active_source_key')->get()
            ->mapWithKeys(fn ($item) => [$this->settledSlot($item->lab_main_work_id, $item->lab_additional_work_id, $item->work_type) => true]);
        $zirconGroups = LabCase::query()->whereHas('mainWorks', fn ($q) => $q->where('material', 'zircon'))
            ->get(['id', 'related_case_id', 'case_relationship'])->map(fn (LabCase $case) => $case->salaryGroupKey())->flip();
        $rows = collect();
        foreach (['main' => LabMainWork::class, 'additional' => LabAdditionalWork::class] as $kind => $model) {
            $works = $model::query()->where(function ($query) use ($employee, $kind): void {
                $query->where('technician_id', $employee->id);
                if ($kind === 'main' && $employee->user_id) {
                    $query->orWhereHas('labCase', fn ($case) => $case->where('modeled_by', $employee->user_id));
                }
                if ($employee->salary_main_technician) {
                    if ($kind === 'main') {
                        $query->orWhereNotNull('lab_case_id');
                    } elseif ($employee->salary_abutment_eligible) {
                        $query->orWhere('work_type', 'individual_abutment');
                    }
                }
            })
                ->whereHas('labCase', function ($query) use ($employee, $from, $until): void {
                    $query->when($from, fn ($q) => $q->whereDate('case_date', '>=', $from))
                        ->when($until, fn ($q) => $q->whereDate('case_date', '<=', $until))
                        ->when($employee->salary_effective_from, fn ($q) => $q->whereDate('case_date', '>=', $employee->salary_effective_from));
                })->with('labCase.patient')->orderBy('id')->get();

            foreach ($works as $work) {
                foreach ($this->rules($employee, $work, $kind, $zirconGroups) as $type) {
                    $key = $kind.'-'.$work->id.'-employee-'.$employee->id.'-'.$type;
                    $rate = $rates->get($type);
                    // Editing a source's material/type cannot pay its already settled role again.
                    $alreadySettled = $settled->has($this->settledSlot($kind === 'main' ? $work->id : null, $kind === 'additional' ? $work->id : null, $type));
                    if ($alreadySettled || ! $rate || $rate->amount < 0 || ! in_array($rate->basis, ['per_unit', 'per_work']) || $work->quantity < 1) {
                        continue;
                    }
                    $rows->put($key, [
                        'lab_main_work_id' => $kind === 'main' ? $work->id : null,
                        'lab_additional_work_id' => $kind === 'additional' ? $work->id : null,
                        'active_source_key' => $key,
                        'work_date' => $work->labCase->case_date->toDateString(),
                        'patient_name' => $work->labCase->patient?->full_name ?? $work->labCase->external_patient_name ?? '—',
                        'work_type' => $type,
                        'quantity' => (int) $work->quantity,
                        'rate_amount' => $rate->amount,
                        'rate_basis' => $rate->basis,
                        'amount_gel' => round((int) round((float) $rate->amount * 100) * ($rate->basis === 'per_work' ? 1 : $work->quantity) / 100, 2),
                    ]);
                }
            }
        }

        return $rows->sortBy('work_date');
    }

    private function settledSlot(?int $mainId, ?int $additionalId, string $type): string
    {
        $role = in_array($type, ['zircon_modeling', 'pmma_modeling', 'abutment_modeling']) ? 'modeling' : 'production';

        return ($mainId ? 'main-'.$mainId : 'additional-'.$additionalId).'-'.$role;
    }

    private function rules(Employee $employee, LabMainWork|LabAdditionalWork $work, string $kind, Collection $zirconGroups): array
    {
        $selected = (int) $work->technician_id === (int) $employee->id;
        $rules = [];
        if ($kind === 'main') {
            if ($work->labCase->modeled_by !== null) {
                $selected = $employee->user_id !== null && (int) $work->labCase->modeled_by === (int) $employee->user_id;
            }
            if ($employee->salary_main_technician) {
                $rules[] = $work->material === 'other' ? 'main_other' : $work->material;
            }
            if ($selected && $employee->salary_modeler && in_array($work->material, ['zircon', 'pmma'])
                && ! ($work->material === 'pmma' && $zirconGroups->has($work->labCase->salaryGroupKey()))) {
                $rules[] = $work->material.'_modeling';
            }

            return $rules;
        }

        return match ($work->work_type) {
            'milling' => $selected && $employee->salary_milling_eligible ? ['milling'] : [],
            'titanium_bar_modeling' => $selected && $employee->salary_balk_eligible ? ['titanium_bar_modeling'] : [],
            'individual_abutment' => [
                ...($employee->salary_main_technician && $employee->salary_abutment_eligible ? ['individual_abutment'] : []),
                ...($selected && $employee->salary_modeler && $employee->salary_abutment_eligible ? ['abutment_modeling'] : []),
            ],
            'other' => $selected ? ['other'] : [],
            default => [],
        };
    }

    public function settle(Employee $employee, array $selected, ?string $from = null, ?string $until = null, array $allocation = []): EmployeeSalarySettlement
    {
        return DB::transaction(function () use ($employee, $selected, $from, $until, $allocation): EmployeeSalarySettlement {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $selected = array_values(array_unique($selected));
            $rows = $this->pending($employee, $from, $until)->only($selected);
            if ($rows->isEmpty() || $rows->count() !== count($selected)) {
                throw ValidationException::withMessages(['selected_items' => __('employees.salary.stale_items')]);
            }
            // Lock the actual sources as well as the employee, including across reassignment.
            LabMainWork::query()->whereIn('id', $rows->pluck('lab_main_work_id')->filter())->orderBy('id')->lockForUpdate()->get();
            LabAdditionalWork::query()->whereIn('id', $rows->pluck('lab_additional_work_id')->filter())->orderBy('id')->lockForUpdate()->get();
            $rows = $this->pending($employee, $from, $until)->only($selected);
            if ($rows->count() !== count($selected)) {
                throw ValidationException::withMessages(['selected_items' => __('employees.salary.stale_items')]);
            }
            $previous = $employee->salarySettlements()->where('salary_type', 'performance')->where('status', 'confirmed')
                ->latest('settled_at')->latest('id')->lockForUpdate()->first();
            $openingCarry = round((float) ($previous?->closing_carry_gel ?? 0), 2);
            $currentSalary = round((float) $rows->sum('amount_gel'), 2);
            $totalDue = round($openingCarry + $currentSalary, 2);
            $settlement = $employee->salarySettlements()->create([
                'salary_type' => 'performance', 'period_from' => $from, 'period_until' => $until,
                'total_gel' => $totalDue, 'current_salary_gel' => $currentSalary,
                'opening_carry_gel' => $openingCarry, 'closing_carry_gel' => $totalDue,
                'settings_snapshot' => ['effective_from' => $employee->salary_effective_from?->toDateString(), 'roles' => $employee->only(array_keys(Employee::salaryRoles()))],
                'status' => 'confirmed', 'settled_at' => now(), 'created_by' => auth()->id(),
            ]);
            $settlement->items()->createMany($rows->values()->all());
            app(EmployeeSalaryFunding::class)->pay($settlement, $allocation);

            return $settlement;
        });
    }

    public function settleFixed(Employee $employee, string $month): EmployeeSalarySettlement
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m']])->validate();

        return DB::transaction(function () use ($employee, $month): EmployeeSalarySettlement {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $start = Carbon::createFromFormat('!Y-m', $month);
            if (! $employee->is_active || ! $employee->salary_active || $employee->salary_type !== 'fixed'
                || $employee->monthly_salary_gel === null || $employee->monthly_salary_gel < 0
                || ($employee->salary_effective_from && $employee->salary_effective_from->gt($start->copy()->endOfMonth()))) {
                throw ValidationException::withMessages(['month' => __('employees.salary.unavailable')]);
            }
            if ($employee->salarySettlements()->where('active_month', $month)->exists()) {
                throw ValidationException::withMessages(['month' => __('employees.salary.month_settled')]);
            }

            return $employee->salarySettlements()->create([
                'salary_type' => 'fixed', 'salary_month' => $month, 'active_month' => $month,
                'period_from' => $start, 'period_until' => $start->copy()->endOfMonth(),
                'total_gel' => $employee->monthly_salary_gel,
                'settings_snapshot' => ['monthly_salary_gel' => $employee->monthly_salary_gel, 'effective_from' => $employee->salary_effective_from?->toDateString()],
                'status' => 'confirmed', 'settled_at' => now(), 'created_by' => auth()->id(),
            ]);
        });
    }

    public function undo(EmployeeSalarySettlement $settlement): void
    {
        DB::transaction(function () use ($settlement): void {
            Employee::query()->whereKey($settlement->employee_id)->lockForUpdate()->firstOrFail();
            $settlement = EmployeeSalarySettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status === 'undone') {
                return;
            }
            if ($settlement->salary_type === 'performance' && EmployeeSalarySettlement::query()
                ->where('employee_id', $settlement->employee_id)->where('salary_type', 'performance')
                ->where('status', 'confirmed')->where('id', '>', $settlement->id)->exists()) {
                throw ValidationException::withMessages(['settlement' => __('employees.salary.undo_latest_only')]);
            }
            app(EmployeeSalaryFunding::class)->reverse($settlement);
            $settlement->items()->update(['active_source_key' => null]);
            $settlement->update(['status' => 'undone', 'active_month' => null, 'undone_at' => now(), 'undone_by' => auth()->id()]);
        });
    }

    private function validatePeriod(?string $from, ?string $until): void
    {
        Validator::make(['from' => $from, 'until' => $until], [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...($from ? ['after_or_equal:from'] : [])],
        ])->validate();
    }
}
