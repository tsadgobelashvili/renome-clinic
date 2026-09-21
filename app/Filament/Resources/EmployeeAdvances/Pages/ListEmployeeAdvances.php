<?php

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdvances extends ListRecords
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('ახალი ავანსი')->icon('heroicon-o-plus')->size('sm')
            ->url(EmployeeAdvanceResource::getUrl('create', ['entry' => 'finance']))];
    }
}
