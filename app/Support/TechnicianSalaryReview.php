<?php

namespace App\Support;

use App\Models\LabCase;
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
                    ->leftJoin('doctors as doctor', 'doctor.id', '=', 'cases.doctor_id')
                    ->leftJoin('employees as assistant', 'assistant.id', '=', 'cases.assistant_employee_id')
                    ->whereIn('work.id', $ids)->get(['work.id', 'work.lab_case_id', 'cases.patient_id',
                        'cases.source', 'cases.external_doctor_name', 'cases.external_billing_doctor_id',
                        'doctor.id as doctor_id', 'doctor.first_name', 'doctor.last_name',
                        'assistant.id as assistant_employee_id', 'assistant.first_name as assistant_first_name',
                        'assistant.last_name as assistant_last_name']) as $source) {
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
                'case_id' => $source?->lab_case_id, 'doctors' => [], 'items' => [], 'total_cents' => 0];
            // These are the Doctor field's three storage forms, not salary/billing recipients.
            // Resolve saved assignments without current role, position or active-status scopes.
            $personKey = null;
            $name = '';
            if ($source?->source === 'external' && filled($source->external_doctor_name)) {
                $case = new LabCase(['external_doctor_name' => $source->external_doctor_name,
                    'external_billing_doctor_id' => $source->external_billing_doctor_id]);
                $personKey = 'external:'.$source->lab_case_id;
                $name = $case->external_doctor_name;
            } elseif ($source?->doctor_id) {
                $personKey = $source->doctor_id;
                $name = trim($source->first_name.' '.$source->last_name);
            } elseif ($source?->assistant_employee_id) {
                $personKey = 'employee:'.$source->assistant_employee_id;
                $name = trim($source->assistant_first_name.' '.$source->assistant_last_name);
            }
            if ($personKey !== null && $name !== '') {
                $groups[$key]['doctors'][$personKey] = $name;
            }
            $groups[$key]['items'][$itemKey] = $row;
            $groups[$key]['total_cents'] += (int) round((float) $row['amount_gel'] * 100);
        }

        return collect($groups)->sortBy(fn ($group) => [$group['date'], $group['patient'], $group['case_id']]);
    }
}
