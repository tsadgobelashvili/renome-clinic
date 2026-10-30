<?php

namespace App\Services;

use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePayrollSetting;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\VisitTreatmentCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicPayrollCycleService
{
    private ?array $deferredReview = null;

    public function __construct(
        private readonly DoctorCompensationCalculator $doctors,
        private readonly EmployeePayrollService $employees,
        private readonly SalarySettlementService $settlements,
    ) {}

    public function nextDate(): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $next = $today->day === 1 ? $today : ($today->day <= 16 ? $today->day(16) : $today->startOfMonth()->addMonth());
        $this->deferredReview = null;
        $lastCycle = ClinicPayrollCycle::query()->whereIn('status', ['partial', 'finalized'])->orderByDesc('payroll_date')->first(['payroll_date', 'snapshot']);
        if (! empty($lastCycle?->snapshot['remaining'])) {
            $this->deferredReview = $this->refreshDeferredDoctorReports($lastCycle->snapshot['remaining']);
            if ($this->deferredReview['doctors'] || $this->deferredReview['employees']) {
                return CarbonImmutable::parse($this->deferredReview['payroll_date']);
            }
            $this->deferredReview = null;
        }
        $last = $lastCycle?->payroll_date;
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
        if ($this->deferredReview !== null) {
            return ['payroll_date' => $date->toDateString(), 'totals' => $this->withDoctorAdvances($this->deferredReview)['totals']];
        }
        $doctors = Doctor::query()->where(fn ($query) => $query->where('is_active', true)->orWhereNotNull('owner_split_key'))->orderBy('id')->get();
        $amounts = $this->doctors->payableSummaries($doctors, clinicCycle: true);
        $totals = [];
        foreach ($amounts as $doctorId => $sources) {
            foreach ($sources['clinic'] ?? [] as $currency => $amount) {
                $amount -= array_sum(app(EmployeeAdvanceService::class)->doctorSalaryAllocation((int) $doctorId, $currency, $amount));
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

    /** Partial cycles retain the originally approved unpaid rows and their periods. */
    public function preview(): array
    {
        $date = $this->nextDate();
        if ($this->deferredReview !== null) {
            $review = $this->withDoctorAdvances($this->deferredReview);

            return [...$review, 'fingerprint' => $this->fingerprint($review)];
        }
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
        $advanceBalances = EmployeeAdvance::whereIn('employee_id', $employees->modelKeys())
            ->where('is_salary_advance', true)->whereDate('date', '<=', today())->withTotals()->get()
            ->groupBy('employee_id')->map(fn ($rows) => $rows->groupBy('currency')->map(fn ($items) => $items->sum(fn ($item) => (float) $item->remaining_amount)));
        foreach ($this->employees->clinicCycleSummaries($employees, $date) as $calculation) {
            $employee = $calculation['employee'];
            $setting = $calculation['setting'];
            $deduction = min($calculation['net_amount'], $advanceBalances[$employee->id][$calculation['currency']] ?? 0);
            unset($calculation['employee'], $calculation['setting']);
            $employeeRows[] = [...$calculation, 'id' => $employee->id, 'name' => $employee->full_name,
                'required_amount' => $calculation['required_amount'] ?? round($calculation['gross_amount'] + $calculation['employer_cost'], 2),
                'salary_advance_applied' => $deduction, 'amount_payable' => round($calculation['net_amount'] - $deduction, 2),
                'salary_advance_balance' => $advanceBalances[$employee->id][$calculation['currency']] ?? 0,
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
        $snapshot = $this->withDoctorAdvances($snapshot);
        // Normalize dates/decimal representations identically for Livewire and JSON storage.
        $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return [...$snapshot, 'fingerprint' => $this->fingerprint($snapshot)];
    }

    public static function rowKey(string $type, array $row): string
    {
        return implode(':', [$type, $row['id'], $row['period_start'], $row['period_end'], $row['setting_id'] ?? '', $row['payday'] ?? '']);
    }

    /** Filter and total the approved rows entirely in memory. */
    public function selectedReview(array $review, array $keys): array
    {
        $review['doctor_totals'] = $review['employee_totals'] = $review['totals'] = [];
        $advanceBalances = [];
        foreach (['doctors', 'employees'] as $type) {
            $review[$type] = array_values(array_filter($review[$type], fn ($row) => in_array(self::rowKey($type, $row), $keys, true)));
            foreach ($review[$type] as &$row) {
                if ($type === 'employees' && array_key_exists('salary_advance_balance', $row)) {
                    $balanceKey = $row['id'].':'.$row['currency'];
                    $advanceBalances[$balanceKey] ??= $row['salary_advance_balance'];
                    $row['salary_advance_applied'] = min($row['net_amount'], $advanceBalances[$balanceKey]);
                    $row['amount_payable'] = round($row['net_amount'] - $row['salary_advance_applied'], 2);
                    $advanceBalances[$balanceKey] = round($advanceBalances[$balanceKey] - $row['salary_advance_applied'], 2);
                }
                foreach ($type === 'doctors' ? ($row['amount_payable'] ?? $row['amounts']) : [$row['currency'] => $row['required_amount']] as $currency => $amount) {
                    $this->add($review[$type === 'doctors' ? 'doctor_totals' : 'employee_totals'], $currency, $amount);
                    $this->add($review['totals'], $currency, $amount);
                }
            }
            unset($row);
        }
        unset($review['fingerprint']);

        return $review;
    }

    public function finalize(string $date, string $fingerprint, User $actor, ?array $selectedKeys = null): ClinicPayrollCycle
    {
        abort_unless($actor->isOwner(), 403);
        if ($selectedKeys === []) {
            throw ValidationException::withMessages(['payroll' => __('clinic-payroll.empty')]);
        }

        return DB::transaction(function () use ($date, $fingerprint, $actor, $selectedKeys) {
            // A unique date row serializes first-time finalization too, without requiring
            // an earlier cycle to exist. Failed/stale approvals roll this draft back.
            ClinicPayrollCycle::query()->insertOrIgnore(['payroll_date' => $date, 'status' => 'draft', 'payment_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $cycle = ClinicPayrollCycle::query()->where('payroll_date', $date)->lockForUpdate()->sole();
            $previous = $cycle->snapshot ?? [];
            if ($cycle->status === 'finalized' || $this->nextDate()->toDateString() !== $date) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            // Same lock order as individual salary fixing; settings and employee
            // locks keep the approved employee calculations stable until commit.
            $doctors = Doctor::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employees = Employee::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            EmployeePayrollSetting::query()->where('source', 'clinic')->orderBy('id')->lockForUpdate()->get();
            $snapshot = $this->preview();
            if (! hash_equals($snapshot['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            $availableKeys = collect(['doctors', 'employees'])->flatMap(fn ($type) => collect($snapshot[$type])->map(fn ($row) => self::rowKey($type, $row)))->all();
            $selectedKeys ??= $availableKeys;
            if (array_filter($selectedKeys, fn ($key) => ! is_string($key)) || count(array_unique($selectedKeys)) !== count($selectedKeys)
                || array_diff($selectedKeys, $availableKeys)) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
            }
            // The existing Owner Split finalizer creates both obligations atomically.
            // Never let it create an unselected doctor's settlement implicitly.
            foreach ($snapshot['doctors'] as $row) {
                foreach ($row['report']['owner_split_preview'] ?? [] as $share) {
                    if ($share['counterpart_share'] <= 0) {
                        continue;
                    }
                    $recipient = $doctors->firstWhere('owner_split_key', $share['counterpart_key']);
                    $recipientRow = collect($snapshot['doctors'])->firstWhere('id', $recipient?->id);
                    if ($recipientRow && in_array(self::rowKey('doctors', $row), $selectedKeys, true)
                        !== in_array(self::rowKey('doctors', $recipientRow), $selectedKeys, true)) {
                        throw ValidationException::withMessages(['payroll' => __('clinic-payroll.linked_owners')]);
                    }
                }
            }
            $remaining = $this->selectedReview($snapshot, array_values(array_diff($availableKeys, $selectedKeys)));
            $snapshot = $this->selectedReview($snapshot, $selectedKeys);
            $deductions = collect($snapshot['employees'])->groupBy(fn ($row) => $row['id'].':'.$row['currency'])
                ->map(fn ($rows) => $rows->sum('salary_advance_applied'));
            foreach ($remaining['employees'] as &$row) {
                if (array_key_exists('salary_advance_balance', $row)) {
                    $row['salary_advance_balance'] = max(0, round($row['salary_advance_balance'] - ($deductions[$row['id'].':'.$row['currency']] ?? 0), 2));
                }
            }
            unset($row);
            $remaining = $this->selectedReview($remaining, array_values(array_diff($availableKeys, $selectedKeys)));
            if ($snapshot['doctors'] === [] && $snapshot['employees'] === []) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.empty')]);
            }
            foreach ($snapshot['doctors'] as $row) {
                if (! $doctors->has($row['id']) || ($row['report'] !== null && ! $doctors[$row['id']]->is_active)) {
                    throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                }
                if ($row['report'] === null) {
                    continue; // Automatically created by the source owner's fix.
                }
                $this->settlements->settle($row['id'], $row['period_start'], $row['period_end'],
                    (float) $row['report']['percentage'], $actor->id,
                    patientGroup: PatientGroup::CLINIC_SLUG, clinicPayrollCycleId: $cycle->id);
            }
            foreach ($snapshot['employees'] as $row) {
                // Reuse the employee batch already loaded under the existing locks.
                $employee = $employees->get($row['id']);
                if ($employee === null) {
                    throw (new ModelNotFoundException)->setModel(Employee::class, [$row['id']]);
                }
                if (! $employee->is_active) {
                    throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                }
                $this->employees->finalize($employee, 'clinic', $row['period_start'], $row['period_end'], $cycle->id, $row['payday']);
            }
            // A concurrent change between review and the existing safe fix must
            // roll back the entire batch, never silently change the approved list.
            $actual = $cycle->doctorSettlements()->whereNotIn('id', $previous['doctor_settlement_ids'] ?? [])->with('items')->get();
            $expectedItems = collect($snapshot['doctors'])->flatMap(fn ($row) => $row['report']['details'] ?? [])
                ->flatMap(fn ($row) => $row['items'])->pluck('id')->sort()->values()->all();
            $actualItems = $actual->flatMap->items->pluck('visit_treatment_case_id')->filter()->sort()->values()->all();
            $actualDoctorTotals = [];
            $actualByDoctor = [];
            foreach ($actual as $settlement) {
                $this->add($actualDoctorTotals, $settlement->currency, (float) $settlement->amount_payable);
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
                if (round((float) $actual->where('doctor_id', $row['id'])->sum('salary_advance_applied'), 2)
                    !== round(array_sum($row['salary_advance_applied'] ?? []), 2)) {
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
            $entries = $cycle->employeeEntries()->whereNotIn('id', $previous['employee_entry_ids'] ?? [])->get();
            $entriesByPeriod = $entries->keyBy(fn ($entry) => $entry->employee_id.':'.$entry->period_start->toDateString().':'.$entry->period_end->toDateString());
            foreach ($snapshot['employees'] as $row) {
                $entry = $entriesByPeriod->get($row['id'].':'.$row['period_start'].':'.$row['period_end']);
                $required = $entry->calculation_details['required_amount'] ?? round((float) $entry->gross_amount + (float) $entry->employer_cost, 2);
                if ((float) $entry->net_amount !== (float) $row['net_amount'] || (float) $required !== (float) $row['required_amount']
                    || (float) $entry->salary_advance_applied !== (float) $row['salary_advance_applied']
                    || $entry->payment_method !== $row['payment_method'] || $entry->currency !== $row['currency']) {
                    throw ValidationException::withMessages(['payroll' => __('clinic-payroll.stale')]);
                }
            }
            // Paid-row snapshots keep their actual deductions; only remaining rows
            // carry a balance for the next selection.
            foreach ($snapshot['employees'] as &$row) {
                unset($row['salary_advance_balance']);
            }
            unset($row);
            foreach (['doctors', 'employees'] as $type) {
                $snapshot[$type] = [...($previous[$type] ?? []), ...$snapshot[$type]];
            }
            $allKeys = collect(['doctors', 'employees'])->flatMap(fn ($type) => collect($snapshot[$type])->map(fn ($row) => self::rowKey($type, $row)))->all();
            $snapshot = $this->selectedReview($snapshot, $allKeys);
            $snapshot['doctor_settlement_ids'] = [...($previous['doctor_settlement_ids'] ?? []), ...$actual->modelKeys()];
            $snapshot['employee_entry_ids'] = [...($previous['employee_entry_ids'] ?? []), ...$entries->modelKeys()];
            $snapshot['remaining'] = ($remaining['doctors'] || $remaining['employees']) ? $remaining : null;
            $cycle->update(['status' => $snapshot['remaining'] ? 'partial' : 'finalized', 'snapshot' => $snapshot, 'cutoff_at' => $cycle->cutoff_at ?? now(),
                'finalized_at' => now(), 'finalized_by' => $actor->id, 'payment_status' => collect($snapshot['doctors'])->every(fn ($row) => $row['payment_method'] === 'cash') && collect($snapshot['employees'])->every(fn ($row) => $row['payment_method'] === 'cash') ? 'paid' : 'pending']);

            return $cycle;
        });
    }

    private function add(array &$totals, string $currency, float $amount): void
    {
        $totals[$currency] = round(($totals[$currency] ?? 0) + $amount, 2);
        ksort($totals);
    }

    /** Reproject only deferred reports containing newly excluded work; paid snapshots stay untouched. */
    private function refreshDeferredDoctorReports(array $review): array
    {
        $counterparts = [];
        $changed = false;
        foreach ($review['doctors'] as &$row) {
            $old = $row['report'] ?? null;
            if ($old === null) {
                continue;
            }
            $items = collect($old['details'])->flatMap(fn ($detail) => $detail['items']);
            $ids = $items->where('source_type', 'visit')->pluck('id')->all();
            $excluded = VisitTreatmentCase::whereKey($ids)->select('id')->withSalaryEligibility()->get()
                ->contains(fn ($item) => (bool) $item->salary_excluded);
            if (! $excluded) {
                continue;
            }
            $changed = true;
            $approved = $items->where('salary_approved', true)->pluck('id')->all();
            $fresh = $this->doctors->finalizableClinicReport($this->doctors->calculate(
                $row['id'], $row['period_start'], $row['period_end'], (float) $old['percentage'],
                patientGroup: PatientGroup::CLINIC_SLUG, approvedFullDiscountItemIds: $approved,
                selectedVisitItemIds: $ids, selectedOwnerShareIds: array_column($old['owner_split_income'], 'id'),
            ));
            foreach ($row['amounts'] as $currency => $amount) {
                // Keep any separate counterpart contribution on this row.
                $row['amounts'][$currency] = round($amount - ($old['totals'][$currency]['doctor_share'] ?? 0)
                    + ($fresh['totals'][$currency]['doctor_share'] ?? 0), 2);
            }
            foreach ($old['owner_split_preview'] as $share) {
                $replacement = collect($fresh['owner_split_preview'])->first(fn ($candidate) => $candidate['currency'] === $share['currency']
                    && $candidate['counterpart_key'] === $share['counterpart_key']);
                $counterparts[] = ['old' => $share, 'new' => $replacement];
            }
            $row['report'] = $fresh;
        }
        unset($row);
        if (! $changed) {
            return $review;
        }
        $keys = Doctor::whereIn('id', array_column($review['doctors'], 'id'))->pluck('owner_split_key', 'id');
        foreach ($review['doctors'] as &$row) {
            foreach ($counterparts as $change) {
                $old = $change['old'];
                if (($keys[$row['id']] ?? null) !== $old['counterpart_key']) {
                    continue;
                }
                $currency = $old['currency'];
                $row['amounts'][$currency] = round(($row['amounts'][$currency] ?? 0) - $old['counterpart_share']
                    + ($change['new']['counterpart_share'] ?? 0), 2);
                $row['counterpart_details'] = array_values(array_filter($row['counterpart_details'] ?? [],
                    fn ($detail) => $detail['source_doctor'] !== $old['source_doctor'] || $detail['currency'] !== $currency));
                if ($change['new'] !== null) {
                    $row['counterpart_details'][] = $change['new'];
                }
            }
        }
        unset($row);
        $review['doctors'] = array_values(array_filter($review['doctors'],
            fn ($row) => collect($row['amounts'])->contains(fn ($amount) => $amount > 0)));

        return $review;
    }

    private function withDoctorAdvances(array $review): array
    {
        foreach ($review['doctors'] as &$row) {
            $row['salary_advance_applied'] = [];
            $row['amount_payable'] = [];
            foreach ($row['amounts'] as $currency => $amount) {
                $applied = array_sum(app(EmployeeAdvanceService::class)->doctorSalaryAllocation($row['id'], $currency, $amount));
                $row['salary_advance_applied'][$currency] = $applied;
                $row['amount_payable'][$currency] = round($amount - $applied, 2);
            }
        }
        unset($row);
        $keys = collect(['doctors', 'employees'])->flatMap(fn ($type) => collect($review[$type])->map(fn ($row) => self::rowKey($type, $row)))->all();

        // Keep deferred JSON snapshots identical to their originally approved form,
        // including integer/decimal representations used by existing stale checks.
        return json_decode(json_encode($this->selectedReview($review, $keys), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
