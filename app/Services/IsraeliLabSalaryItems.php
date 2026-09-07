<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabMainWork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class IsraeliLabSalaryItems
{
    public const PMMA_RATE = 25.0;

    public function eligible(Doctor $doctor, ?string $from = null, ?string $until = null): Collection
    {
        $items = LabMainWork::query()
            ->whereIn('material', ['zircon', 'pmma'])
            ->whereDoesntHave('salarySettlementItem')
            ->whereHas('labCase', fn (Builder $query): Builder => $query
                ->where('doctor_id', $doctor->getKey())->where('source', 'israeli')
                ->when($from !== null, fn (Builder $query): Builder => $query->whereDate('case_date', '>=', $from))
                ->when($until !== null, fn (Builder $query): Builder => $query->whereDate('case_date', '<=', $until)))
            ->with('labCase.patient')->get();

        $groupKeys = $items->map(fn (LabMainWork $item) => $item->labCase->salaryGroupKey())->unique();
        // Look across the whole existing work group, regardless of dates or settlement state.
        $zirconGroups = LabCase::query()
            ->where(fn (Builder $query): Builder => $query->whereIn('id', $groupKeys)
                ->orWhere(fn (Builder $query): Builder => $query->whereIn('related_case_id', $groupKeys)->where('case_relationship', 'same_case')))
            ->whereHas('mainWorks', fn (Builder $query): Builder => $query->where('material', 'zircon'))
            ->get()->map(fn (LabCase $case) => $case->salaryGroupKey());

        return $items->filter(fn (LabMainWork $item): bool => $item->material === 'zircon'
            ? (float) $doctor->israeli_lab_zircon_rate > 0
            : ! $zirconGroups->contains($item->labCase->salaryGroupKey()))->values();
    }
}
