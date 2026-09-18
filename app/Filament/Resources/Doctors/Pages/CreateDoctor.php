<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateDoctor extends CreateRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('შენახვა')->size('sm');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('გაუქმება')->size('sm');
    }
}
