<?php

namespace App\Services;

use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePayrollSetting;
use App\Models\PatientGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicPayrollCycleService
{
    public function __construct(
        private readonly DoctorCompensationCalculator $doctors,
        private readonly EmployeePayrollService $employees,
        private readonly SalarySettlementService $settlements,
    ) {}

    public function nextDate(): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $next = $today->day === 1 ? $today : ($today->day <= 16 ? $today->day(16) : $today->startOfMonth()->addMonth());
        $last = ClinicPayrollCycle::query()->where('status', 'finalized')->max('payroll_date');
        if ($last) {
            $last = CarbonImmutable::parse($last);
            $next = $next->max($last->day === 1 ? $last->day(16) : $last->startOfMonth()->addMonth());
        }

        return $next;
    }

    /** Compact card: batched numeric inputs, with full visit detail deferred to review. */
    public function overview(): array
    {
        $date = $this->nextDate();
        $doctors = Doctor::query()->where(fn ($query) => $query->where('is_active', true)->orWhereNotNull('owner_split_key'))->orderBy('id')->get();
        $amounts = $this->doctors->payableSummaries($doctors, clinicCycle: true);
        $totals = [];
        foreach ($amounts as $sources) {
            foreach ($sources['clinic'] ?? [] as $currency => $amount) {
                $this->add($totals, $currency, $amount);
            }
        }
        $employees = Employee::query()->where('is_active', true)
            ->whereHas('position', fn ($query) => $query->where('is_technician', false))
            ->with(['payrollSettings' => fn ($query) => $query->where('source', 'clinic')->where('is_active', true)])
            ->orderBy('id')->get();
        foreach ($this->employees->clinicCycleSummaries($employees, $date) as $row) {
            $this->add($totals, $row['currency'], $row['required_amount'] ?? round($row['gross_amount'] + $row['employer_cost'], 2));
        }

        return ['payroll_date' => $date->toDateString(), 'totals' => $totals ?: ['GEL' => 0.0]];
    }

    /** One authoritative review using the existing salary calculators; never cached persistently. */
    public function preview(): array
    {
        $date = $this->nextDate();
        $until = CarbonImmutable::today()->min($date)->toDateString();
        $doctors = Doctor::query()->where(fn ($query) => $query->where('is_active', true)->orWhereNotNull('owner_split_key'))->orderBy('id')->get();
        $rows = [];
        $counterparts = [];
        foreach ($doctors->where('is_active', true) as $doctor) {
            $from = $this->doctors->defaultPeriodStart($doctor->id, PatientGroup::CLINIC_SLUG);
            $report = $this->doctors->finalizableClinicReport($this->doctors->calculate($doctor->id, $from, $until, patientGroup: PatientGroup::CLINIC_SLUG));
            $amounts = collect($report['totals'])->map(fn ($total) => (float) $total['doctor_share'])->all();
            if (! collect($amounts)->contains(fn ($amount) => $amount > 0)) {
                continue;
            }
            $rows[$doctor->id] = ['id' => $doctor->id, 'name' => $doctor->full_name, 'amounts' => $amounts,
                'period_start' => $from, 'period_end' => $until, 'payment_method' => $doctor->clinic_salary_payment_method ?? 'bank_transfer', 'report' => $report];
            foreach ($report['owner_split_preview'] as $share) {
                $recipient = $doctors->firstWhere('owner_split_key', $share['counterpart_key']);
                if ($recipient && $share['counterpart_share'] > 0) {
                    $counterparts[] = ['doctor' => $recipient, 'share' => $share, 'from' => $from];
                }
            }
        }
        // The existing fix service automatically fixes the other owner's share too.
        // Include it in approval instead of creating an unreviewed extra obligation.
        foreach ($counterparts as $data) {
            $doctor = $data['doctor'];
            $share = $data['share'];
            $rows[$doctor->id] ??= ['id' => $doctor->id, 'name' => $doctor->full_name, 'amounts' => [],
                'period_start' => $data['from'], 'period_end' => $until, 'payment_method' => $doctor->clinic_salary_payment_method ?? 'bank_transfer', 'report' => null];
            $this->add($rows[$doctor->id]['amounts'], $share['currency'], $share['counterpart_share']);
            $rows[$doctor->id]['counterpart_details'][] = $share;
        }
        ksort($rows);

        $employees = Employee::query()->where('is_active', true)
            ->whereHas('position', fn ($query) => $query->where('is_technician', false))
            ->with(['position', 'payrollSettings' => fn ($query) => $query->where('source', 'clinic')->where('is_active', true)])
            ->orderBy('id')->get();
        $employeeRows = [];
        foreach ($this->employees->clinicCycleSummaries($employees, $date) as $calculation) {
            $employee = $calculation['employee'];
            $setting = $calculation['setting'];
            unset($calculation['employee'], $calculation['setting']);
            $employeeRows[] = [...$calculation, 'id' => $employee->id, 'name' => $employee->full_name,
                'required_amount' => $calculation['required_amount'] ?? round($calculation['gross_amount'] + $calculation['employer_cost'], 2),
                'setting_id' => $setting->id, 'settings_snapshot' => $setting->attributesToArray()];
        }
        $doctorTotals = [];
        $employeeTotals = [];
        foreach ($rows as $row) {
            foreach ($row['amounts'] as $currency => $amount) {
                $this->add($doctorTotals, $currency, $amount);
            }
        }
        foreach ($employeeRows as $row) {
            $this->add($employeeTotals, $row['currency'], $row['required_amount']);
        }
        $totals = $doctorTotals;
        foreach ($employeeTotals as $currency => $amount) {
            $this->add($totals, $currency, $amount);
        }
        $snapshot = ['payroll_date' => $date->toDateString(), 'doctors' => array_values($rows), 'employees' => $employeeRows,
            'doctor_totals' => $doctorTotals, 'employee_totals' => $employeeTotals, 'totals' => $totals ?: ['GEL' => 0.0]];
        // Normalize dates/decimal representations identically for Livewire and JSON storage.
        $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return [...$snapshot, 'fingerprint' => $this->fingerprint($snapshot)];
    }

    public function finalize(string $date, string $fingerprint, User $actor): ClinicPayrollCycle
    {
        abort_unless($actor->isOwner(), 403);

        return DB::transaction(function () use ($date, $fingerprint, $actor) {
            // A unique date row serializes first-time finalization too, without requiring
            // an earlier cycle to exist. Failed/stale approvals roll this draft back.
            ClinicPayrollCycle::query()->insertOrIgnore(['payroll_date' => $date, 'status' => 'draft', 'payment_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $cycle = ClinicPayrollCycle::query()->where('payroll_date', $date)->lockForUpdate()->sole();
            if ($cycle->status === 'finalized' || $this->nextDate()->toDateString() !== $date) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            // Same lock order as individual salary fixing; settings and employee
            // locks keep the approved employee calculations stable until commit.
            Doctor::query()->orderBy('id')->lockForUpdate()->get();
            Employee::query()->orderBy('id')->lockForUpdate()->get();
            EmployeePayrollSetting::query()->where('source', 'clinic')->orderBy('id')->lockForUpdate()->get();
            $snapshot = $this->preview();
            if (! hash_equals($snapshot['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            if ($snapshot['doctors'] === [] && $snapshot['employees'] === []) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.empty')]);
            }
            foreach ($snapshot['doctors'] as $row) {
                if ($row['report'] === null) {
                    continue; // Automatically created by the source owner's fix.
                }
                $this->settlements->settle($row['id'], $row['period_start'], $row['period_end'],
                    (float) $row['report']['percentage'], $actor->id,
                    patientGroup: PatientGroup::CLINIC_SLUG, clinicPayrollCycleId: $cycle->id);
            }
            foreach ($snapshot['employees'] as $row) {
                $this->employees->finalize(Employee::findOrFail($row['id']), 'clinic', $row['period_start'], $row['period_end'], $cycle->id, $row['payday']);
            }
            // A concurrent change between review and the existing safe fix must
            // roll back the entire batch, never silently change the approved list.
            $actual = $cycle->doctorSettlements()->with('items')->get();
            $expectedItems = collect($snapshot['doctors'])->flatMap(fn ($row) => $row['report']['details'] ?? [])
                ->flatMap(fn ($row) => $row['items'])->pluck('id')->sort()->values()->all();
            $actualItems = $actual->flatMap->items->pluck('visit_treatment_case_id')->filter()->sort()->values()->all();
            $actualDoctorTotals = [];
            $actualByDoctor = [];
            foreach ($actual as $settlement) {
                $this->add($actualDoctorTotals, $settlement->currency, (float) $settlement->salary_total);
                $actualByDoctor[$settlement->doctor_id] ??= [];
                $this->add($actualByDoctor[$settlement->doctor_id], $settlement->currency, (float) $settlement->salary_total);
            }
            if ($actualItems !== $expectedItems || array_filter($actualDoctorTotals) != array_filter($snapshot['doctor_totals'])) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            foreach ($snapshot['doctors'] as $row) {
                if (array_filter($actualByDoctor[$row['id']] ?? []) != array_filter($row['amounts'])) {
                    throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                }
            }
            $fixedItems = $actual->flatMap->items->keyBy('visit_treatment_case_id');
            foreach (collect($snapshot['doctors'])->flatMap(fn ($row) => $row['report']['details'] ?? [])->flatMap(fn ($row) => $row['items']) as $item) {
                $fixed = $fixedItems->get($item['id']);
                foreach (['quantity' => 'quantity_snapshot', 'revenue' => 'revenue', 'paid_amount' => 'paid_amount_snapshot',
                    'direct_expense' => 'direct_expense', 'salary_base' => 'salary_base', 'doctor_share' => 'doctor_share'] as $key => $field) {
                    if (round((float) $fixed->{$field}, 2) !== round((float) $item[$key], 2)) {
                        throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                    }
                }
            }
            $entries = $cycle->employeeEntries()->get()->keyBy('employee_id');
            foreach ($snapshot['employees'] as $row) {
                $entry = $entries->get($row['id']);
                $required = $entry->calculation_details['required_amount'] ?? round((float) $entry->gross_amount + (float) $entry->employer_cost, 2);
                if ((float) $entry->net_amount !== (float) $row['net_amount'] || (float) $required !== (float) $row['required_amount']
                    || $entry->payment_method !== $row['payment_method'] || $entry->currency !== $row['currency']) {
                    throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                }
            }
            $snapshot['doctor_settlement_ids'] = $actual->modelKeys();
            $snapshot['employee_entry_ids'] = $entries->modelKeys();
            $cycle->update(['status' => 'finalized', 'snapshot' => $snapshot, 'cutoff_at' => now(),
                'finalized_at' => now(), 'finalized_by' => $actor->id, 'payment_status' => collect($snapshot['doctors'])->every(fn ($row) => $row['payment_method'] === 'cash') && collect($snapshot['employees'])->every(fn ($row) => $row['payment_method'] === 'cash') ? 'paid' : 'pending']);

            return $cycle;
        });
    }

    private function add(array &$totals, string $currency, float $amount): void
    {
        $totals[$currency] = round(($totals[$currency] ?? 0) + $amount, 2);
        ksort($totals);
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
