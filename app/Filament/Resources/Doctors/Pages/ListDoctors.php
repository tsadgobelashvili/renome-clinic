<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Resources\Pages\ListRecords;

class ListDoctors extends ListRecords
{
    protected static string $resource = DoctorResource::class;

    public function getHeading(): null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'renome-record-list-page', 'renome-doctors-list-page'];
    }
}
