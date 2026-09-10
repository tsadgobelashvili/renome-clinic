<?php

namespace App\Filament\Resources\LabTechnicians\Pages;

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;

class EditLabTechnician extends EditEmployee
{
    protected static string $resource = LabTechnicianResource::class;
}
