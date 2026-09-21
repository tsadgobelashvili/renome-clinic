<?php

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Services\EmployeeAdvanceService;
use App\Models\CashboxDay;
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

    #[Locked]
    public ?string $entrySource = null;

    #[Locked]
    public ?string $cashboxDate = null;

    public function mount(): void
    {
        parent::mount();
        $this->postingKey = (string) Str::uuid();

        if (request()->query('entry') === 'finance') {
            $this->entrySource = 'accumulated_cash';
        } elseif (request()->query('entry') === 'cashbox') {
            $day = CashboxDay::findOrFail(request()->query('cashbox_day'));
            $this->entrySource = 'cashbox';
            $this->cashboxDate = $day->date->toDateString();
        }

        if ($this->entrySource !== null) {
            $this->data['source'] = $this->entrySource;
            $this->data['date'] = $this->cashboxDate ?? today()->toDateString();
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        if ($this->entrySource !== null) {
            $data['source'] = $this->entrySource;
            $data['bank_transaction_id'] = null;
        }
        if ($this->cashboxDate !== null) {
            $data['date'] = $this->cashboxDate;
        }

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
