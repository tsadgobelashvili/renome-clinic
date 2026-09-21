<?php

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Services\EmployeeAdvanceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateEmployeeAdvance extends CreateRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $postingKey;

    public function mount(): void
    {
        parent::mount();
        $this->postingKey = (string) Str::uuid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(EmployeeAdvanceService::class)->issue([...$data, 'posting_key' => $this->postingKey], auth()->user());
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('ავანსის გაცემა');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('view', ['record' => $this->record]);
    }
}
