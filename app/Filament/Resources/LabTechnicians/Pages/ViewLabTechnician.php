<?php

namespace App\Filament\Resources\LabTechnicians\Pages;

use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;

class ViewLabTechnician extends ViewEmployee
{
    protected static string $resource = LabTechnicianResource::class;
}
