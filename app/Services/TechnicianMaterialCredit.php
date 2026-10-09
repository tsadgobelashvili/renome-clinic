<?php

namespace App\Services;

use App\Models\EmployeeSalarySettlement;
use App\Models\LabMainWork;
use App\Support\Money;
use Closure;
use Illuminate\Support\Collection;

/** Read-only reconciliation of modeling entitlement against recorded salary snapshots. */
final class TechnicianMaterialCredit
{
    public function adjustments(Collection $employees, Closure $price): array
    {
        $employees = $employees->where('salary_modeler', true)->keyBy('id');
        if ($employees->isEmpty()) {
            return [];
        }
        $history = EmployeeSalarySettlement::query()->whereIn('employee_id', $employees->keys())
            ->where('salary_type', 'performance')->where('status', 'confirmed')
            ->with(['items' => fn ($query) => $query->whereNotNull('active_source_key')->orderBy('id')])
            ->orderBy('settled_at')->orderBy('id')->get();
        if (! $history->contains(fn ($settlement) => $settlement->items->contains('work_type', 'pmma_modeling'))) {
            return [];
        }
        $modeling = $history->flatMap->items->whereIn('work_type', ['pmma_modeling', 'zircon_modeling']);
        $works = LabMainWork::query()->whereIn('id', $modeling->pluck('lab_main_work_id')->filter()->unique())
            ->with('labCase')->get()->keyBy('id');
        $pmmaGroups = $modeling->where('work_type', 'pmma_modeling')
            ->map(fn ($item) => $works->get($item->lab_main_work_id)?->labCase?->salaryGroupKey())->filter()->unique();
        $zircon = LabMainWork::query()->where('material', 'zircon')
            ->where(fn ($query) => $query->whereIn('technician_id', $employees->keys())
                ->orWhereHas('labCase', fn ($query) => $query->whereIn('modeled_by', $employees->pluck('user_id')->filter())))
            ->whereHas('labCase', fn ($query) => $query->whereIn('id', $pmmaGroups)
                ->orWhere(fn ($query) => $query->where('case_relationship', 'same_case')->whereIn('related_case_id', $pmmaGroups)))
            ->with('labCase')->orderBy('id')->get()->keyBy('id');
        $works = $works->union($zircon)->sortKeys();
        $groups = $queues = [];
        foreach ($history as $settlement) {
            $employeeId = $settlement->employee_id;
            $queues[$employeeId] ??= [];
            foreach ($settlement->items as $item) {
                $case = $works->get($item->lab_main_work_id)?->labCase;
                $group = $case && in_array($item->work_type, ['pmma_modeling', 'zircon_modeling'], true) ? $case->salaryGroupKey() : null;
                $amount = Money::minorUnits($item->amount_gel);
                $queues[$employeeId][] = ['group' => $group, 'remaining' => $amount];
                if ($group !== null) {
                    $groups[$employeeId][$group]['accrued'] = ($groups[$employeeId][$group]['accrued'] ?? 0) + $amount;
                    if ($item->work_type === 'zircon_modeling') {
                        $gross = Money::minorUnits($item->rate_amount) * ($item->rate_basis === 'per_work' ? 1 : $item->quantity);
                        $groups[$employeeId][$group]['zircon'] = ($groups[$employeeId][$group]['zircon'] ?? 0) + $gross;
                    }
                }
            }
            foreach (($settlement->settings_snapshot['modeling_carry_reductions'] ?? []) as $group => $amount) {
                $reduction = Money::minorUnits($amount);
                $groups[$employeeId][$group]['accrued'] = ($groups[$employeeId][$group]['accrued'] ?? 0) - $reduction;
                $this->consume($queues[$employeeId], $reduction, (int) $group);
            }
            // Partial salaries pay the oldest carried items first, then this payout's
            // snapshot order. Never attribute a payout to another employee or patient.
            $paid = $settlement->actual_paid_gel ?? ((float) $settlement->total_gel - (float) $settlement->closing_carry_gel);
            $this->consume($queues[$employeeId], Money::minorUnits($paid));
        }
        $pending = [];
        foreach ($works as $work) {
            if ($work->material !== 'zircon' || ! $work->labCase) {
                continue;
            }
            foreach ($employees as $employee) {
                foreach ($price($employee, $work) as $key => $row) {
                    if ($row['work_type'] === 'zircon_modeling') {
                        $pending[$employee->id][$work->labCase->salaryGroupKey()][$key] = Money::minorUnits($row['amount_gel']);
                    }
                }
            }
        }
        $result = [];
        foreach ($pending as $employeeId => $cases) {
            foreach ($cases as $group => $rows) {
                $prior = $groups[$employeeId][$group] ?? [];
                // Previous net Zirconia snapshots already include any earlier credit.
                $credit = max(0, ($prior['accrued'] ?? 0) - ($prior['zircon'] ?? 0));
                if ($credit === 0) {
                    continue;
                }
                foreach ($rows as $key => $gross) {
                    $used = min($gross, $credit);
                    $credit -= $used;
                    $result[$employeeId][$key] = ['amount_gel' => round(($gross - $used) / 100, 2),
                        'material_credit_gel' => $used / 100, 'material_group' => $group,
                        'carry_reduction_gel' => 0.0, 'overpayment_gel' => 0.0];
                }
                $unpaid = collect($queues[$employeeId] ?? [])->where('group', $group)->sum('remaining');
                $reduction = min($credit, $unpaid);
                $last = array_key_last($rows);
                $result[$employeeId][$last]['carry_reduction_gel'] = $reduction / 100;
                $result[$employeeId][$last]['overpayment_gel'] = ($credit - $reduction) / 100;
            }
        }

        return $result;
    }

    private function consume(array &$queue, int $amount, ?int $group = null): void
    {
        foreach ($queue as &$item) {
            if ($group !== null && $item['group'] !== $group) {
                continue;
            }
            $used = min($amount, $item['remaining']);
            $item['remaining'] -= $used;
            $amount -= $used;
            if ($amount <= 0) {
                break;
            }
        }
    }
}
