<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()
            ->disabled(fn (): bool => $this->record->assistantLabCases()->exists() || $this->record->additionalLabWorks()->exists() || $this->record->mainLabWorks()->exists() || $this->record->salarySettlements()->exists())
            ->tooltip(fn (): ?string => $this->record->additionalLabWorks()->exists() ? __('employees.delete_blocked') : null)];
    }
}
