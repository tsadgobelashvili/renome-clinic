<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayrollSetting;
use App\Models\PatientGroup;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeePayrollService
{
    /** @return array<string, mixed> */
    public function calculate(Employee $employee, string $source, string $periodStart, string $periodEnd): array
    {
        $period = $this->validatePeriod($source, $periodStart, $periodEnd);
        $setting = $this->availableSetting($employee, $source, $period['end']);

        $calculation = $this->calculateSetting($setting, $period);
        $applied = array_sum(app(EmployeeAdvanceService::class)->salaryAllocation($employee->id, $calculation['currency'], $calculation['net_amount']));

        return [...$calculation, 'salary_advance_applied' => $applied, 'amount_payable' => round($calculation['net_amount'] - $applied, 2)];
    }

    private function calculateSetting(EmployeePayrollSetting $setting, array $period, ?object $aggregate = null): array
    {
        $calculationStart = $setting->effective_from && $setting->effective_from->gt($period['start'])
            ? CarbonImmutable::instance($setting->effective_from)
            : $period['start'];

        $baseAmount = 0.0;
        $eligibleUnits = 0;
        if (in_array($setting->salary_model, ['percentage', 'per_unit'], true)) {
            $aggregate ??= $this->aggregateQuery($setting, $calculationStart, $period['end'])->first();
            $eligibleUnits = (int) $aggregate->eligible_units;
            $baseAmount = round((float) $aggregate->eligible_base, 2);
        }

        $deductions = round((float) $setting->employee_deductions, 2);
        $employerCost = round((float) $setting->employer_cost, 2);
        [$gross, $net, $baseAmount] = match ($setting->salary_model) {
            'fixed_net' => [
                round((float) $setting->net_amount + $deductions, 2),
                round((float) $setting->net_amount, 2),
                round((float) $setting->net_amount, 2),
            ],
            'fixed_gross' => [
                round((float) $setting->gross_amount, 2),
                max(0, round((float) $setting->gross_amount - $deductions, 2)),
                round((float) $setting->gross_amount, 2),
            ],
            'percentage' => [
                round($baseAmount * (float) $setting->percentage_rate / 100, 2),
                max(0, round($baseAmount * (float) $setting->percentage_rate / 100 - $deductions, 2)),
                $baseAmount,
            ],
            'per_unit' => [
                round($eligibleUnits * (float) $setting->per_unit_amount, 2),
                max(0, round($eligibleUnits * (float) $setting->per_unit_amount - $deductions, 2)),
                round($eligibleUnits * (float) $setting->per_unit_amount, 2),
            ],
        };

        $clinicAmounts = $setting->source === 'clinic' && $setting->salary_model === 'fixed_net'
            ? ClinicEmployeePayrollAmounts::fromNet($net, $setting->default_payment_method) : [];
        if ($setting->source === 'clinic' && $setting->default_payment_method === 'cash') {
            $clinicAmounts['required_amount'] = $net;
        }

        return [
            'setting' => $setting,
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'calculation_start' => $calculationStart->toDateString(),
            'source' => $setting->source,
            'salary_model' => $setting->salary_model,
            'base_amount' => $baseAmount,
            'eligible_units' => $eligibleUnits,
            'gross_amount' => $gross,
            'net_amount' => $net,
            'deductions' => $deductions,
            'employer_cost' => $employerCost,
            'currency' => $setting->currency,
            'payment_method' => $setting->default_payment_method,
            ...$clinicAmounts,
        ];
    }

    /** Current periods and amounts, with one batched aggregate query for variable salaries. */
    public function payableSummaries(Collection $employees): array
    {
        $cutoffs = $this->latestFinalizedEntries($employees)->keyBy(fn ($entry) => $entry->employee_id.'|'.$entry->source);
        $summaries = [];
        $periods = [];
        $aggregateQuery = null;
        foreach ($employees as $employee) {
            foreach ($this->effectiveSettings($employee->payrollSettings, CarbonImmutable::today()) as $setting) {
                $last = $cutoffs->get($employee->id.'|'.$setting->source);
                $start = $last ? CarbonImmutable::instance($last->period_end)->addDay()
                    : ($setting->effective_from ? CarbonImmutable::instance($setting->effective_from) : CarbonImmutable::instance($setting->created_at)->startOfMonth());
                // A finalized monthly period must not generate another fixed salary
                // merely because its cutoff was earlier than the configured payday.
                $payday = $last
                    ? $this->payoutDate($employee, CarbonImmutable::parse($last->calculation_details['payout_date'] ?? $last->period_end)->startOfMonth()->addMonth())
                    : $this->periodPayoutDate($employee, $start);
                if ($start->isAfter(today()) || $payday->startOfMonth()->isAfter(CarbonImmutable::today()->startOfMonth())) {
                    continue;
                }
                $period = ['start' => $start, 'end' => CarbonImmutable::today()];
                $periods[$setting->id] = compact('employee', 'setting', 'period', 'payday');
                if (in_array($setting->salary_model, ['percentage', 'per_unit'], true)) {
                    $calculationStart = $setting->effective_from && $setting->effective_from->gt($start) ? CarbonImmutable::instance($setting->effective_from) : $start;
                    $query = $this->aggregateQuery($setting, $calculationStart, $period['end'])
                        ->selectRaw('? as setting_id', [$setting->id]);
                    $aggregateQuery = $aggregateQuery ? $aggregateQuery->unionAll($query) : $query;
                }
            }
        }
        $aggregates = $aggregateQuery ? $aggregateQuery->get()->keyBy('setting_id') : collect();
        foreach ($periods as $id => $data) {
            $calculation = $this->calculateSetting($data['setting'], $data['period'], $aggregates->get($id));
            $summaries[] = [...$calculation, 'employee' => $data['employee'], 'entry' => null, 'payday' => $data['payday']->toDateString()];
        }

        return array_values(array_filter($summaries, fn ($row) => $row['net_amount'] > 0));
    }

    /** Latest active configuration per source, effective by the period's end. */
    public function effectiveSettings(Collection $settings, CarbonImmutable $periodEnd): Collection
    {
        return $settings->filter(fn (EmployeePayrollSetting $setting) => $setting->is_active
                && (! $setting->effective_from || $setting->effective_from->toDateString() <= $periodEnd->toDateString()))
            ->sortByDesc(fn (EmployeePayrollSetting $setting) => ($setting->effective_from?->toDateString() ?? '0000-00-00').sprintf('%020d', $setting->id))
            ->unique('source')->values();
    }

    public function payoutDate(Employee $employee, CarbonImmutable $month): CarbonImmutable
    {
        $day = $employee->salary_payout_day ?: 1;

        return $month->startOfMonth()->day(min($day, $month->daysInMonth));
    }

    /** Clinic monthly paydays due by the shared cycle, including overdue unpaid salaries. */
    public function clinicCycleSummaries(Collection $employees, CarbonImmutable $cycleDate): array
    {
        $lastEntries = $this->latestFinalizedEntries($employees)->where('source', 'clinic')->keyBy('employee_id');
        $periods = [];
        $aggregateQuery = null;
        foreach ($employees as $employee) {
            if (! $employee->salary_payout_day) {
                continue;
            }
            $settings = $employee->payrollSettings->where('source', 'clinic')->where('is_active', true);
            $setting = $settings->sortBy(fn ($setting) => $setting->effective_from?->toDateString() ?? $setting->created_at->toDateString())->first();
            if (! $setting) {
                continue;
            }
            $last = $lastEntries->get($employee->id);
            $start = $last ? CarbonImmutable::instance($last->period_end)->addDay()
                : CarbonImmutable::instance($setting->effective_from ?? $setting->created_at->startOfMonth());
            $lastPayday = $last ? CarbonImmutable::parse($last->calculation_details['payout_date'] ?? $last->period_end) : null;
            $payday = $lastPayday ? $this->payoutDate($employee, $lastPayday->startOfMonth()->addMonth()) : $this->periodPayoutDate($employee, $start);
            if ($setting->effective_from && $payday->lt($setting->effective_from)) {
                $payday = $this->periodPayoutDate($employee, CarbonImmutable::instance($setting->effective_from));
            }
            if ($payday->gt($cycleDate)) {
                continue;
            }
            $setting = $this->effectiveSettings($settings, $payday)->first();
            if (! $setting) {
                continue;
            }
            $variable = in_array($setting->salary_model, ['percentage', 'per_unit'], true);
            if ($variable) {
                // A zero-work earlier payday must not trap variable salaries there
                // forever. The next due cycle covers all work since the last fix.
                $latestDue = $this->payoutDate($employee, $cycleDate);
                if ($latestDue->gt($cycleDate)) {
                    $latestDue = $this->payoutDate($employee, $cycleDate->startOfMonth()->subMonth());
                }
                $payday = $payday->max($latestDue);
                $setting = $this->effectiveSettings($settings, $payday)->first();
                $variable = in_array($setting->salary_model, ['percentage', 'per_unit'], true);
            }
            $end = $variable ? $payday->min(CarbonImmutable::today()) : $payday;
            if ($start->gt($end) || ($setting->effective_from && $setting->effective_from->gt($end))) {
                continue;
            }
            // Select the configuration effective for this employee's actual period.
            $setting = $this->effectiveSettings($employee->payrollSettings, $end)->firstWhere('source', 'clinic');
            if (! $setting) {
                continue;
            }
            $variable = in_array($setting->salary_model, ['percentage', 'per_unit'], true);
            $period = compact('start', 'end');
            $periods[$setting->id] = compact('employee', 'setting', 'period', 'payday');
            if ($variable) {
                $query = $this->aggregateQuery($setting, $start->max($setting->effective_from ?? $start), $end)->selectRaw('? as setting_id', [$setting->id]);
                $aggregateQuery = $aggregateQuery ? $aggregateQuery->unionAll($query) : $query;
            }
        }
        $aggregates = $aggregateQuery ? $aggregateQuery->get()->keyBy('setting_id') : collect();

        return collect($periods)->map(function ($data, $id) use ($aggregates) {
            return [...$this->calculateSetting($data['setting'], $data['period'], $aggregates->get($id)),
                'employee' => $data['employee'], 'payday' => $data['payday']->toDateString()];
        })->filter(fn ($row) => $row['net_amount'] > 0)->values()->all();
    }

    /** One row per employee/source, without loading their payroll history into PHP. */
    public function latestFinalizedEntries(Collection $employees): Collection
    {
        $ranked = PayrollEntry::query()->whereIn('employee_id', $employees->pluck('id'))
            ->where(fn ($query) => $query->where('status', 'finalized')->orWhere('payout_status', 'paid'))
            ->select(['id', 'employee_id', 'source', 'period_end', 'calculation_details', 'clinic_payroll_cycle_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY employee_id, source ORDER BY period_end DESC, id DESC) AS payroll_rank');

        return PayrollEntry::query()->fromSub($ranked, 'payroll_entries')->where('payroll_rank', 1)->get();
    }

    private function periodPayoutDate(Employee $employee, CarbonImmutable $start): CarbonImmutable
    {
        $date = $this->payoutDate($employee, $start);

        return $date->lt($start) ? $this->payoutDate($employee, $start->startOfMonth()->addMonth()) : $date;
    }

    private function aggregateQuery(EmployeePayrollSetting $setting, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->eligibleItemsQuery($setting, $start, $end)
            ->selectRaw('COALESCE(SUM(visit_treatment_cases.quantity), 0) as eligible_units')
            ->selectRaw('COALESCE(SUM(visit_treatment_cases.quantity * visit_treatment_cases.unit_price * CASE WHEN COALESCE(visit_treatment_cases.currency, visits.currency) = visits.currency THEN 1 ELSE COALESCE(visit_treatment_cases.exchange_rate, 0) END), 0) as eligible_base');
    }

    public function finalize(Employee $employee, string $source, string $periodStart, string $periodEnd, ?int $clinicPayrollCycleId = null, ?string $payoutDate = null): PayrollEntry
    {
        abort_if($clinicPayrollCycleId !== null && $source !== 'clinic', 422);

        return DB::transaction(function () use ($employee, $source, $periodStart, $periodEnd, $clinicPayrollCycleId, $payoutDate): PayrollEntry {
            $employee = Employee::query()->with('position')->lockForUpdate()->findOrFail($employee->getKey());
            if ($source === 'clinic') {
                $payoutDate ??= $this->periodPayoutDate($employee, CarbonImmutable::parse($periodStart))->toDateString();
                $last = $this->latestFinalizedEntries(collect([$employee]))->firstWhere('source', 'clinic');
                $lastPayday = $last ? ($last->calculation_details['payout_date']
                    ?? $this->payoutDate($employee, CarbonImmutable::instance($last->period_end))->toDateString()) : null;
                if ($lastPayday && $payoutDate <= $lastPayday) {
                    throw ValidationException::withMessages(['period_start' => __('employees.payroll.period_finalized')]);
                }
            }
            $existing = PayrollEntry::query()
                ->where('employee_id', $employee->getKey())
                ->where('source', $source)
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages(['period_start' => __('employees.payroll.period_finalized')]);
            }

            $calculation = $this->calculate($employee, $source, $periodStart, $periodEnd);
            /** @var EmployeePayrollSetting $setting */
            $setting = EmployeePayrollSetting::query()->lockForUpdate()->findOrFail($calculation['setting']->getKey());
            $calculation = $this->calculate($employee, $source, $periodStart, $periodEnd);

            $run = PayrollRun::query()->create([
                'period_start' => $calculation['period_start'],
                'period_end' => $calculation['period_end'],
                'source' => $source,
                'status' => 'finalized',
                'finalized_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $entry = $run->entries()->create([
                'clinic_payroll_cycle_id' => $clinicPayrollCycleId,
                'employee_id' => $employee->getKey(),
                'employee_payroll_setting_id' => $setting->getKey(),
                'period_start' => $calculation['period_start'],
                'period_end' => $calculation['period_end'],
                'source' => $source,
                'salary_model' => $setting->salary_model,
                'settings_snapshot' => $setting->only([
                    'source', 'salary_model', 'currency', 'default_payment_method', 'effective_from',
                    'net_amount', 'gross_amount', 'percentage_rate', 'per_unit_amount', 'category',
                    'treatment_case_id', 'taxable', 'employee_deductions', 'employer_cost',
                    'tax_settings_reference',
                ]),
                'calculation_details' => [
                    ...($payoutDate ? ['payout_date' => $payoutDate] : []),
                    'calculation_start' => $calculation['calculation_start'],
                    'eligible_units' => $calculation['eligible_units'],
                    'eligible_base' => $calculation['base_amount'],
                    'category' => $setting->category,
                    'treatment_case_id' => $setting->treatment_case_id,
                    ...array_intersect_key($calculation, array_flip(['taxable_salary', 'income_tax', 'employee_pension', 'employer_pension', 'required_amount'])),
                ],
                'base_amount' => $calculation['base_amount'],
                'gross_amount' => $calculation['gross_amount'],
                'net_amount' => $calculation['net_amount'],
                'deductions' => $calculation['deductions'],
                'employer_cost' => $calculation['employer_cost'],
                'currency' => $calculation['currency'],
                'payment_method' => $calculation['payment_method'],
                'payout_status' => 'pending',
                'matching_status' => 'unmatched',
                'status' => 'draft',
                'finalized_at' => now(),
            ]);
            if ($calculation['salary_advance_applied'] > 0) {
                app(EmployeeAdvanceService::class)->applySalary($entry, auth()->user());
            }
            $entry->update(['status' => 'finalized',
                'payout_status' => (float) $entry->amount_payable === 0.0 ? 'paid' : 'pending']);
            app(ClinicPayrollCashPosting::class)->record($entry);

            return $entry->refresh();
        });
    }

    private function availableSetting(Employee $employee, string $source, CarbonImmutable $periodEnd): EmployeePayrollSetting
    {
        $employee->loadMissing('position');
        if (! $employee->is_active || $employee->position?->is_technician) {
            throw ValidationException::withMessages(['employee' => __('employees.payroll.unavailable')]);
        }

        $setting = $this->effectiveSettings($employee->payrollSettings()->where('source', $source)->get(), $periodEnd)->first();

        if (! $setting) {
            throw ValidationException::withMessages(['source' => __('employees.payroll.unavailable')]);
        }

        return $setting;
    }

    private function eligibleItemsQuery(EmployeePayrollSetting $setting, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        $groupSlug = $setting->source === 'israeli'
            ? PatientGroup::ISRAEL_PARTNER_SLUG
            : PatientGroup::CLINIC_SLUG;

        return DB::table('visit_treatment_cases')
            ->join('visits', 'visits.id', '=', 'visit_treatment_cases.visit_id')
            ->join('patients', 'patients.id', '=', 'visits.patient_id')
            ->join('patient_groups', 'patient_groups.id', '=', 'patients.patient_group_id')
            ->leftJoin('treatment_cases', 'treatment_cases.id', '=', 'visit_treatment_cases.treatment_case_id')
            ->whereNull('visits.cancelled_at')
            ->whereDate('visits.visit_date', '>=', $periodStart->toDateString())
            ->whereDate('visits.visit_date', '<=', $periodEnd->toDateString())
            ->where('patient_groups.slug', $groupSlug)
            ->when($setting->salary_model === 'percentage', fn ($query) => $query->where('visits.currency', $setting->currency))
            ->when($setting->category, fn ($query, string $category) => $query->where('treatment_cases.category', $category))
            ->when($setting->treatment_case_id, fn ($query, int $treatmentCaseId) => $query->where('visit_treatment_cases.treatment_case_id', $treatmentCaseId));
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    private function validatePeriod(string $source, string $periodStart, string $periodEnd): array
    {
        Validator::make(compact('source', 'periodStart', 'periodEnd'), [
            'source' => ['required', 'in:clinic,israeli'],
            'periodStart' => ['required', 'date_format:Y-m-d'],
            'periodEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodStart'],
        ])->validate();

        return [
            'start' => CarbonImmutable::createFromFormat('!Y-m-d', $periodStart),
            'end' => CarbonImmutable::createFromFormat('!Y-m-d', $periodEnd),
        ];
    }
}
