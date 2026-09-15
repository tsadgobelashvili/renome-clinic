<?php

namespace App\Support;

use App\Models\LabCase;
use App\Models\Patient;
use App\Models\Visit;

class PartnerPatientHistory
{
    public static function rows(Patient $patient): array
    {
        $visits = $patient->visits()->with(['doctor', 'treatmentCaseItems.treatmentCase'])
            ->orderByDesc('visit_date')->orderByDesc('id')->limit(50)->get()
            ->map(fn (Visit $visit): array => [
                'key' => 'visit-'.$visit->id, 'date' => $visit->visit_date, 'type' => 'visit',
                'doctor' => $visit->doctor?->full_name ?? 'ექიმი —',
                'work' => $visit->treatmentCaseItems->map(fn ($item): string => $item->display_name.' ×'.(int) $item->quantity)->join(', ') ?: 'მანიპულაცია არ არის',
            ]);
        // Patient ownership is independent of visits and of the patient's current source group.
        $cases = $patient->labCases()->with(['doctor', 'assistantEmployee', 'modeler.employee', 'miller.employee', 'mainWorks.technicianEmployee', 'additionalWorks.technicianEmployee'])
            ->orderByDesc('case_date')->orderByDesc('id')->limit(50)->get()
            ->map(function (LabCase $case): array {
                $work = $case->mainWorks->map(fn ($row): string => (LabCase::MATERIALS[$row->material] ?? $row->material).' × '.$row->quantity)->join(', ');
                if ($work === '' && $case->material) {
                    $work = (LabCase::MATERIALS[$case->material] ?? $case->material).' × '.$case->quantity;
                }
                $performers = $case->mainWorks->map(fn ($row) => $row->technicianEmployee?->full_name);
                if ($case->modeled_by !== null) {
                    $performers->push($case->modeler?->employee?->full_name ?: $case->modeler?->name);
                }
                $additional = $case->additionalWorks->map(function ($row): string {
                    $performer = $row->technicianEmployee?->full_name ?: $row->technician;

                    return __('lab.additional_types.'.$row->work_type).' × '.$row->quantity
                        .($performer ? ' — '.$performer : '').($row->note ? ' ('.$row->note.')' : '');
                });
                if ($case->milling_quantity && ! $case->additionalWorks->contains('work_type', 'milling')) {
                    $miller = $case->miller?->employee?->full_name ?: ($case->miller?->name ?: $case->milling_technician);
                    $additional->push(__('lab.milling').' × '.$case->milling_quantity.($miller ? ' — '.$miller : ''));
                }

                return [
                    'key' => 'lab-'.$case->id, 'date' => $case->case_date, 'type' => 'lab',
                    'doctor' => $case->doctor_display, 'work' => $work ?: '—',
                    'technician' => $performers->filter()->unique()->join(', ') ?: ($case->modeling ?: '—'),
                    'shade' => $case->mainWorks->isNotEmpty() ? ($case->mainWorks->pluck('shade')->filter()->unique()->join(', ') ?: '—') : ($case->shade ?: '—'),
                    'additional' => $additional->join(' · '), 'notes' => $case->notes,
                    'details' => $case->mainWorks->isNotEmpty()
                        ? $case->mainWorks->map(fn ($row): array => [
                            'material' => LabCase::MATERIALS[$row->material] ?? $row->material,
                            'quantity' => $row->quantity, 'shade' => $row->shade ?: '—',
                            'technician' => collect([$row->technicianEmployee?->full_name,
                                $case->modeler?->employee?->full_name ?: $case->modeler?->name])->filter()->unique()->join(', ') ?: '—',
                        ])->all()
                        : [['material' => LabCase::MATERIALS[$case->material] ?? $case->material ?? '—',
                            'quantity' => $case->quantity ?? '—', 'shade' => $case->shade ?: '—',
                            'technician' => $performers->filter()->unique()->join(', ') ?: ($case->modeling ?: '—')]],
                ];
            });

        return $visits->concat($cases)->sortByDesc(fn (array $row): string => $row['date']->format('Y-m-d').'|'.$row['key'])
            ->take(50)->values()->all();
    }
}
