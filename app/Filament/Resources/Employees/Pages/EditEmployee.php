<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Concerns\RedirectsToCanonicalPersonUrl;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    use RedirectsToCanonicalPersonUrl;

    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('index');
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('employees.save'));
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()
            ->disabled(fn (): bool => $this->record->assistantLabCases()->exists() || $this->record->additionalLabWorks()->exists() || $this->record->mainLabWorks()->exists() || $this->record->salarySettlements()->exists())
            ->tooltip(fn (): ?string => $this->record->additionalLabWorks()->exists() ? __('employees.delete_blocked') : null)];
    }
}
