<?php

namespace App\Filament\Support;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;

class PersonnelNavigation
{
    public const RESOURCES = [DoctorResource::class, EmployeeResource::class, LabTechnicianResource::class];

    public static function tabs(): array
    {
        $user = auth()->user();
        // The existing panel middleware restricts lab users to lab cases/profile.
        if (! $user?->is_active || $user->isLabTechnician()) {
            return [];
        }

        $tabs = [];
        foreach (array_combine(self::RESOURCES, ['doctors', 'employees', 'technicians']) as $resource => $label) {
            if ($resource::canViewAny()) {
                $tabs[] = ['resource' => $resource, 'label' => __('personnel.'.$label), 'url' => $resource::getUrl('index')];
            }
        }

        return $tabs;
    }

    public static function isActive(): bool
    {
        foreach (self::RESOURCES as $resource) {
            if (request()->routeIs($resource::getRouteBaseName().'.*')) {
                return true;
            }
        }

        return false;
    }
}
