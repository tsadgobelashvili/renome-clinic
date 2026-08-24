<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Visit;
use Filament\Resources\Pages\CreateRecord;

class CreateVisit extends CreateRecord
{
    protected static string $resource = VisitResource::class;

    public function getTitle(): string
    {
        return 'ახალი ვიზიტი';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** @var array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>}|null */
    public ?array $pendingPayment = null;

    public bool $redirectingToEstimate = false;

    public function mount(): void
    {
        parent::mount();

        $patientId = request()->integer('patient_id');
        $doctorId = request()->integer('doctor_id');
        $state = $this->form->getRawState();

        if ($patientId && Patient::query()->whereKey($patientId)->exists()) {
            $state['patient_id'] = $patientId;
        }

        if ($doctorId && Doctor::query()->whereKey($doctorId)->where('is_active', true)->exists()) {
            $state['doctor_id'] = $doctorId;
        }

        $this->form->fill($state);
    }

    /** @param array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>} $data */
    public function submitPayment(array $data): void
    {
        $this->pendingPayment = $data;
        $this->create();
    }

    public function getCurrentRemainingAmount(): ?float
    {
        return $this->calculateRemainingAmount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $state = $this->form->getRawState();
        $data['total_price'] = Visit::totalFromTreatmentItemState(
            $state['treatmentCaseItems'] ?? [],
            null,
            ($state['visit_type'] ?? 'treatment') === 'consultation'
                ? ($state['consultation_fee'] ?? 0)
                : 0,
        );

        return $data;
    }

    public function openTreatmentEstimate(): void
    {
        $this->redirectingToEstimate = true;
        $this->pendingPayment = null;
        $this->create();
    }

    protected function afterCreate(): void
    {
        $this->record->syncTreatmentItemsTotal();

        if ($this->pendingPayment === null) {
            return;
        }

        Payment::createWithSplits([
            'visit_id' => $this->record->getKey(),
            'amount' => $this->pendingPayment['amount'],
            'currency' => $this->pendingPayment['currency'] ?? $this->record->currency,
            'payment_date' => now()->toDateString(),
        ], $this->pendingPayment['splits']);

        $this->pendingPayment = null;
    }

    protected function getRedirectUrl(): string
    {
        if ($this->redirectingToEstimate) {
            return TreatmentEstimateResource::getUrl('create', [
                'visit_id' => $this->record->getKey(),
                'patient_id' => $this->record->patient_id,
                'doctor_id' => $this->record->doctor_id,
            ]);
        }

        return VisitResource::getUrl('edit', ['record' => $this->record]);
    }

    private function calculateRemainingAmount(): ?float
    {
        $state = $this->form->getRawState();

        if (blank($state['total_price'] ?? null)) {
            return null;
        }

        $totalPrice = (float) $state['total_price'];
        $discountValue = (float) ($state['discount_value'] ?? 0);
        $discountAmount = ($state['discount_type'] ?? 'amount') === 'percent'
            ? round($totalPrice * $discountValue / 100, 2)
            : $discountValue;

        return round(max(0, $totalPrice - $discountAmount), 2);
    }
}
