<?php

namespace App\Filament\Support;

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Pages\TechnicianSalaries;

/** Navigation only: salary calculations and actions stay on their existing pages. */
class SalaryNavigation
{
    public static function tabs(): array
    {
        $tabs = [];
        if (DoctorCompensation::canAccess()) {
            foreach (['doctors' => 'doctor_tab', 'employees' => 'employee_tab'] as $mode => $label) {
                $tabs[] = [
                    'mode' => $mode,
                    'label' => __('salaries.'.$label),
                    'url' => DoctorCompensation::getUrl($mode === 'employees' ? ['staff' => $mode] : []),
                ];
            }
        }
        if (TechnicianSalaries::canAccess()) {
            $tabs[] = ['mode' => 'technicians', 'label' => __('salaries.technician_tab'), 'url' => TechnicianSalaries::getUrl()];
        }

        return $tabs;
    }
}
