<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use Filament\Resources\Pages\ListRecords;

class ListPatients extends ListRecords
{
    protected static string $resource = PatientResource::class;

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
        return [...parent::getPageClasses(), 'renome-record-list-page', 'renome-patients-list-page'];
    }

    public function toggleDebtFilter(): void
    {
        $current = data_get($this->tableFilters, 'financial_status.value');
        data_set($this->tableFilters, 'financial_status.value', $current === 'debt' ? null : 'debt');
        $this->updatedTableFilters();
    }
}
