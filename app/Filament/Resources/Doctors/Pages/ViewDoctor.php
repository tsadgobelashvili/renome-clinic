<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDoctor extends ViewRecord
{
    protected static string $resource = DoctorResource::class;

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
