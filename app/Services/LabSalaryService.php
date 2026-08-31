<?php

namespace App\Services;

use App\Models\LabCase;
use App\Models\LabSalarySettlement;
use App\Models\LabWorkItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LabSalaryService
{
    public function eligibleItems(int $technicianId, string $from, string $until): Collection
    {
        $items = LabWorkItem::query()
            ->where('technician_id', $technicianId)->where('status', 'completed')
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $until)
            ->whereDoesntHave('settlementItem')
            ->with(['labCase.patient', 'labCase.doctor'])->orderBy('work_date')->orderBy('id')->get();

        $groupKeys = $items->map(fn (LabWorkItem $item) => $item->labCase->salaryGroupKey())->unique();
        $groupCaseIds = LabCase::query()
            ->whereIn('id', $groupKeys)
            ->orWhere(fn ($query) => $query->whereIn('related_case_id', $groupKeys)->where('case_relationship', 'same_case'))
            ->pluck('id');
        $zirconDesignGroups = LabWorkItem::query()->whereIn('lab_case_id', $groupCaseIds)
            ->where('work_type', 'zirconia')->where('component_type', 'design')->with('labCase')->get()
            ->map(fn (LabWorkItem $item) => $item->labCase->salaryGroupKey())->unique();

        return $items->reject(fn (LabWorkItem $item) => $item->work_type === 'pmma'
            && $item->component_type === 'design'
            && $zirconDesignGroups->contains($item->labCase->salaryGroupKey()))->values();
    }

    public function calculate(int $technicianId, string $from, string $until): array
    {
        $items = $this->eligibleItems($technicianId, $from, $until);

        return ['items' => $items, 'total' => round((float) $items->sum('salary_amount'), 2)];
    }

    public function settle(int $technicianId, string $from, string $until, ?int $createdBy = null): LabSalarySettlement
    {
        return DB::transaction(function () use ($technicianId, $from, $until, $createdBy): LabSalarySettlement {
            $report = $this->calculate($technicianId, $from, $until);
            $ids = $report['items']->pluck('id');
            LabWorkItem::query()->whereKey($ids)->lockForUpdate()->get();
            if ($ids->isEmpty()) {
                throw new \DomainException('There is no unsettled completed laboratory work in this period.');
            }
            if (LabWorkItem::query()->whereKey($ids)->whereHas('settlementItem')->exists()) {
                throw new \DomainException('One or more work items have already been settled.');
            }
            $settlement = LabSalarySettlement::create(['technician_id' => $technicianId, 'period_start' => $from, 'period_end' => $until, 'salary_total' => $report['total'], 'status' => 'confirmed', 'settled_at' => now(), 'created_by' => $createdBy]);
            foreach ($report['items'] as $item) {
                $settlement->items()->create(['lab_work_item_id' => $item->id, 'quantity_snapshot' => $item->quantity, 'rate_snapshot' => $item->rate_snapshot, 'salary_snapshot' => $item->salary_amount]);
            }

            return $settlement;
        });
    }

    public function undo(LabSalarySettlement $settlement): void
    {
        DB::transaction(function () use ($settlement): void {
            $settlement->lockForUpdate()->first();
            $settlement->items()->delete();
            $settlement->delete();
        });
    }
}
