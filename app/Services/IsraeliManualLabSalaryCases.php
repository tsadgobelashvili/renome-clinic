<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabMainWork;
use App\Models\PatientGroup;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Manual strategy for the existing Israeli calculator and settlement writer. */
class IsraeliManualLabSalaryCases
{
    public function settledCaseIds(): Collection
    {
        return SalarySettlementItem::whereNotNull('manual_lab_case_id')->get(['manual_lab_case_id', 'manual_case_snapshot'])
            ->flatMap(fn ($item) => [$item->manual_lab_case_id, ...($item->manual_case_snapshot['lab_case_ids'] ?? [])])->unique();
    }

    public function eligible(Doctor $doctor, ?string $from = null, ?string $until = null): Collection
    {
        if (! $doctor->usesManualIsraeliLabSalary()) {
            return collect();
        }
        $groupIds = LabCase::where('doctor_id', $doctor->id)->where('source', 'israeli')->get(['id', 'related_case_id', 'case_relationship'])
            ->map(fn (LabCase $case): int => $case->salaryGroupKey())->unique();
        if ($groupIds->isEmpty()) {
            return collect();
        }
        $cases = LabCase::query()->where(fn ($q) => $q->whereIn('id', $groupIds)
            ->orWhere(fn ($q) => $q->whereIn('related_case_id', $groupIds)->where('case_relationship', 'same_case')))
            ->with(['mainWorks.salarySettlementItem', 'patient'])
            ->orderBy('case_date')->orderBy('id')->get();
        $settledGroups = $this->settledCaseIds();

        return $cases->groupBy(fn (LabCase $case): int => $case->salaryGroupKey())->map(function (Collection $members, int $groupId) use ($doctor, $from, $until, $settledGroups, $cases): ?array {
            $root = $cases->firstWhere('id', $groupId);
            if (! $root || $settledGroups->intersect($members->pluck('id'))->isNotEmpty()
                || $members->contains(fn (LabCase $case): bool => $case->source !== 'israeli'
                    || (int) $case->doctor_id !== (int) $doctor->id || $case->patient_id !== $root->patient_id)) {
                return null;
            }
            $works = $members->flatMap->mainWorks->sortBy('id')->values();
            if ($works->isEmpty() || $works->contains(fn (LabMainWork $work): bool => $work->salarySettlementItem !== null)
                || ($from !== null && $root->case_date->toDateString() < $from)
                || ($until !== null && $root->case_date->toDateString() > $until)) {
                return null;
            }

            return ['id' => $groupId, 'root' => $root, 'cases' => $members, 'works' => $works];
        })->filter()->values();
    }

    public function lockGroups(array $ids): void
    {
        // Parent locks also serialize new work/related-case inserts through their foreign keys.
        $cases = LabCase::where(fn ($q) => $q->whereIn('id', $ids)
            ->orWhere(fn ($q) => $q->whereIn('related_case_id', $ids)->where('case_relationship', 'same_case')))
            ->orderBy('id')->lockForUpdate()->get();
        LabMainWork::whereIn('lab_case_id', $cases->modelKeys())->orderBy('id')->lockForUpdate()->get();
    }

    public function approve(Doctor $doctor, string $from, string $until, array $ids, array $amounts, ?int $actorId): void
    {
        if ($ids === [] && $amounts === []) {
            return;
        }
        Gate::forUser(User::findOrFail($actorId))->authorize('manageCompensation', Doctor::class);
        if (! $doctor->usesManualIsraeliLabSalary()) {
            throw ValidationException::withMessages(['manual_case_amounts' => __('manual-lab-salary.unavailable')]);
        }
        validator(['ids' => $ids], ['ids' => 'array', 'ids.*' => 'integer|min:1|distinct'])->validate();
        $eligible = $this->eligible($doctor, $from, $until)->pluck('id')->all();
        foreach ($ids as $id) {
            if (! in_array((int) $id, $eligible, true)) {
                throw ValidationException::withMessages(['selected_manual_case_ids' => __('manual-lab-salary.unavailable')]);
            }
            validator(['manual_case_amounts' => $amounts], [
                'manual_case_amounts.'.$id => 'required|numeric|min:0|max:99999999999.99|decimal:0,2',
            ])->validate();
        }
    }

    public function details(Doctor $doctor, string $from, string $until, array $amounts = [], ?array $selectedIds = null): array
    {
        return $this->eligible($doctor, $from, $until)
            ->filter(fn (array $group): bool => $selectedIds === null || in_array($group['id'], array_map('intval', $selectedIds), true))
            ->map(function (array $group) use ($amounts): array {
                $root = $group['root'];
                $rawAmount = $amounts[$group['id']] ?? null;
                $amount = is_numeric($rawAmount) && (float) $rawAmount >= 0 ? Money::decimal($rawAmount) : 0.0;
                $works = $group['works']->map(fn (LabMainWork $work): array => [
                    'id' => $work->id, 'lab_case_id' => $work->lab_case_id, 'material' => $work->material,
                    'quantity' => (int) $work->quantity, 'shade' => $work->shade,
                    'work_date' => $group['cases']->firstWhere('id', $work->lab_case_id)->case_date->toDateString(),
                    'technician_id' => $work->technician_id,
                ])->all();
                $description = collect($works)->map(fn ($work) => strtoupper($work['material']).' ×'.$work['quantity'])->join(', ');
                $snapshot = ['mode' => 'manual_case', 'group_id' => $group['id'],
                    'lab_case_ids' => $group['cases']->pluck('id')->all(), 'patient_id' => $root->patient_id,
                    'patient' => $root->patient?->lab_name ?? '—', 'work_date' => $root->case_date->toDateString(),
                    'service' => $description, 'amount_gel' => $amount, 'works' => $works];
                $items = $group['works']->values()->map(fn (LabMainWork $work, int $index): array => [
                    'id' => $work->id, 'source_type' => 'lab', 'name' => strtoupper($work->material),
                    'quantity' => (int) $work->quantity, 'unit_rate' => null,
                    'manual_lab_case_id' => $index === 0 ? $group['id'] : null,
                    'manual_case_snapshot' => $index === 0 ? $snapshot : ['mode' => 'manual_case', 'link_only' => true],
                    'revenue' => $index === 0 ? $amount : 0.0, 'direct_expense' => 0.0,
                    'salary_base' => $index === 0 ? $amount : 0.0, 'applied_percentage' => null,
                    'doctor_share' => $index === 0 ? $amount : 0.0,
                    'paid_amount' => $index === 0 ? $amount : 0.0, 'outstanding_amount' => 0.0,
                ])->all();

                return ['visit_id' => null, 'lab_case_id' => $group['id'], 'manual_case_id' => $group['id'],
                    'manual_amount' => $rawAmount, 'source_key' => 'manual-case-'.$group['id'],
                    'visit_date' => $root->case_date->format('d.m.Y'), 'patient' => $snapshot['patient'],
                    'manipulations' => $description, 'works' => $works, 'items' => $items,
                    'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG, 'patient_group_name' => 'Israeli',
                    'currency' => 'GEL', 'source_type' => 'manual_lab_case',
                    'work_total' => $amount, 'total_value' => $amount, 'discount_total' => 0.0,
                    'final_payable' => $amount, 'paid_total' => $amount, 'outstanding_total' => 0.0,
                    'expense_total' => 0.0, 'base_total' => $amount, 'doctor_share' => $amount,
                    'owner_split' => false, 'owner_split_override' => null];
            })->values()->all();
    }
}
