<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabMainWork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class IsraeliLabSalaryItems
{
    public function unitRate(LabMainWork $work, Doctor $doctor): float
    {
        return (float) ($work->material === 'pmma' ? $doctor->israeli_lab_pmma_rate : $doctor->israeli_lab_zircon_rate);
    }

    public function eligible(Doctor $doctor, ?string $from = null, ?string $until = null): Collection
    {
        return $this->eligibleForDoctors(collect([$doctor]), $from, $until);
    }

    public function amount(LabMainWork $work, Doctor $doctor): float
    {
        return round($work->quantity * $this->unitRate($work, $doctor), 2);
    }

    public function eligibleForDoctors(\Illuminate\Support\Collection $doctors, ?string $from = null, ?string $until = null, bool $compact = false): Collection
    {
        $doctors = $doctors->keyBy('id');
        $items = LabMainWork::query()
            ->select(['id', 'lab_case_id', 'material', 'quantity'])
            ->whereIn('material', ['zircon', 'pmma'])
            ->whereDoesntHave('salarySettlementItem')
            ->whereHas('labCase', fn (Builder $query): Builder => $query
                ->whereIn('doctor_id', $doctors->keys())->where('source', 'israeli')
                ->when($from !== null, fn (Builder $query): Builder => $query->whereDate('case_date', '>=', $from))
                ->when($until !== null, fn (Builder $query): Builder => $query->whereDate('case_date', '<=', $until)))
            ->with($compact ? ['labCase:id,doctor_id,case_date,related_case_id,case_relationship'] : ['labCase.patient'])->get();

        $groupKeys = $items->map(fn (LabMainWork $item) => $item->labCase->salaryGroupKey())->unique();
        // Look across the whole existing work group, regardless of dates or settlement state.
        $zirconGroups = LabCase::query()
            ->select(['id', 'related_case_id', 'case_relationship'])
            ->where(fn (Builder $query): Builder => $query->whereIn('id', $groupKeys)
                ->orWhere(fn (Builder $query): Builder => $query->whereIn('related_case_id', $groupKeys)->where('case_relationship', 'same_case')))
            ->whereHas('mainWorks', fn (Builder $query): Builder => $query->where('material', 'zircon'))
            ->get()->map(fn (LabCase $case) => $case->salaryGroupKey());

        return $items->filter(fn (LabMainWork $item): bool => $this->unitRate($item, $doctors->get($item->labCase->doctor_id)) > 0
            && ($item->material === 'zircon' || ! $zirconGroups->contains($item->labCase->salaryGroupKey())))->values();
    }
}
