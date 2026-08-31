<?php

namespace App\Filament\Resources\TreatmentEstimates\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use Filament\Resources\Pages\CreateRecord;

class CreateTreatmentEstimate extends CreateRecord
{
    protected static string $resource = TreatmentEstimateResource::class;

    public ?int $patientId = null;

    public ?int $visitId = null;

    public function mount(): void
    {
        parent::mount();

        $patientId = request()->integer('patient_id');
        $doctorId = request()->integer('doctor_id');
        $visitId = request()->integer('visit_id');
        $state = $this->form->getRawState();

        if ($patientId && Patient::query()->whereKey($patientId)->exists()) {
            $this->patientId = $patientId;
            $state['patient_id'] = $patientId;
        }

        if ($doctorId && Doctor::query()->whereKey($doctorId)->where('is_active', true)->exists()) {
            $state['doctor_id'] = $doctorId;
        }

        if ($visitId && Visit::query()->whereKey($visitId)
            ->where('patient_id', $state['patient_id'] ?? 0)
            ->exists()) {
            $this->visitId = $visitId;
        }

        $this->form->fill($state);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($this->patientId !== null) {
            $data['patient_id'] = $this->patientId;
        }

        if ($this->visitId && Visit::query()->whereKey($this->visitId)
            ->where('patient_id', $data['patient_id'])
            ->exists()) {
            $data['visit_id'] = $this->visitId;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return TreatmentEstimateResource::getUrl('view', ['record' => $this->record]);
    }
}
