<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Presentation metadata only. Input must be the current technician's eligible salary rows. */
class TechnicianSalaryReview
{
    public const GROUPS_PER_PAGE = 25;

    public const ITEMS_PER_PAGE = 20;

    public function groups(int $technicianId, Collection $eligible): Collection
    {
        $sources = [];
        foreach (['lab_main_work_id' => 'lab_main_works', 'lab_additional_work_id' => 'lab_additional_works'] as $field => $table) {
            foreach ($eligible->pluck($field)->filter()->unique()->chunk(1000) as $ids) {
                foreach (DB::table($table.' as work')->join('lab_cases as cases', 'cases.id', '=', 'work.lab_case_id')
                    ->whereIn('work.id', $ids)->get(['work.id', 'work.lab_case_id', 'cases.patient_id']) as $source) {
                    $sources[$field][$source->id] = $source;
                }
            }
        }

        $groups = [];
        foreach ($eligible as $itemKey => $row) {
            $field = $row['lab_main_work_id'] ? 'lab_main_work_id' : 'lab_additional_work_id';
            $source = $sources[$field][$row[$field]] ?? null;
            // Never fetch other work belonging to this patient/case. Keep the
            // supplied eligible rows and original settlement selection keys intact.
            $key = hash('sha256', json_encode([$technicianId, $source?->patient_id,
                $source?->lab_case_id ?? $field.':'.$row[$field], $row['work_date']]));
            $groups[$key] ??= ['key' => $key, 'date' => $row['work_date'], 'patient' => $row['patient_name'],
                'case_id' => $source?->lab_case_id, 'items' => [], 'total_cents' => 0];
            $groups[$key]['items'][$itemKey] = $row;
            $groups[$key]['total_cents'] += (int) round((float) $row['amount_gel'] * 100);
        }

        return collect($groups)->sortBy(fn ($group) => [$group['date'], $group['patient'], $group['case_id']]);
    }
}
