<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\PartnerVisitPaymentRecorder;
use App\Services\PaymentProcessor;
use App\Services\ProductSaleService;
use App\Support\Currency;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateVisit extends CreateRecord
{
    protected static string $resource = VisitResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function getTitle(): string
    {
        return 'ახალი ვიზიტი';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** @var array{amount: mixed, currency?: string, splits: array<int, array{payment_method: string, amount: mixed}>}|null */
    public ?array $pendingPayment = null;

    public bool $returnToDashboard = false;

    public function mount(): void
    {
        parent::mount();

        $this->returnToDashboard = request()->query('return') === 'dashboard';

        $patientId = request()->integer('patient_id');
        $doctorId = request()->integer('doctor_id');
        $state = $this->form->getRawState();

        if (request()->boolean('tomography')) {
            $state['visit_type'] = 'consultation';
        }

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
        $this->stagePayment($data);

        Notification::make()
            ->success()
            ->title('გადახდა დამატებულია')
            ->body('გადახდა შეინახება ვიზიტის შექმნასთან ერთად.')
            ->send();
    }

    public function submitCombinedPayment(array $data): void
    {
        $this->submitPayment($data);
    }

    /** @param array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>} $data */
    public function submitPaymentAndCreate(array $data): void
    {
        $this->pendingPayment = Money::minorUnits($data['amount'] ?? 0) > 0
            ? $this->preparedPendingPayment($data)
            : null;
        $this->returnToDashboard = true;
        $this->create();
    }

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('ვიზიტი და გადახდა ვერ შეინახა')
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('ვიზიტი და გადახდა ვერ შეინახა')
                ->body('დაფიქსირდა ტექნიკური შეცდომა. მონაცემები არ შენახულა.')
                ->send();

            throw $exception;
        }
    }

    public function getCurrentRemainingAmount(): ?float
    {
        return $this->calculateRemainingAmount();
    }

    public function getCurrentFinalPayableAmount(): ?float
    {
        return $this->calculateFinalPayableAmount();
    }

    public function getStagedPaidAmount(): float
    {
        return $this->pendingPayment === null ? 0.0 : Money::decimal($this->pendingPayment['amount']);
    }

    public function getStagedPaymentSummary(): string
    {
        if ($this->pendingPayment === null) {
            return '—';
        }

        return collect($this->pendingPayment['splits'])
            ->map(fn (array $split): string => PaymentMethod::labelFor($split['payment_method'])
                .' '.Currency::format(
                    $split['amount'],
                    $split['currency'] ?? $this->pendingPayment['currency'] ?? Currency::DEFAULT,
                ))
            ->implode(' · ');
    }

    /** @return array{amount: mixed, currency: string, splits: array<int, array{payment_method: string, amount: mixed}>}|null */
    public function getPendingPaymentFormData(): ?array
    {
        return $this->pendingPayment;
    }

    public function hasPendingPayment(): bool
    {
        return $this->pendingPayment !== null;
    }

    public function stagedPaymentExceedsPayable(): bool
    {
        $payable = $this->calculateFinalPayableAmount() + $this->pendingProductTotal($this->pendingPayment['products'] ?? []);

        return $payable !== null
            && Money::minorUnits($this->getStagedPaidAmount()) > Money::minorUnits($payable);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $state = $this->form->getRawState();
        VisitForm::validatePatientTreatmentRequirement(
            $state['patient_id'] ?? null,
            (array) ($state['treatmentCaseItems'] ?? []),
        );
        $data['total_price'] = Visit::totalFromTreatmentItemState(
            $state['treatmentCaseItems'] ?? [],
            null,
            ($state['visit_type'] ?? 'treatment') === 'consultation'
                ? ($state['consultation_fee'] ?? 0)
                : 0,
            $state['currency'] ?? Currency::DEFAULT,
        );

        $this->validatePendingPaymentAgainst($data['total_price'], $state);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncTreatmentItemsTotal();

        if ($this->pendingPayment !== null) {
            $this->persistCombinedPayment($this->pendingPayment);

            $this->pendingPayment = null;
        }

    }

    private function persistCombinedPayment(array $data): void
    {
        $patient = $this->record->patient()->with('patientGroup')->firstOrFail();
        if ($patient->isIsraelPartner()) {
            app(PartnerVisitPaymentRecorder::class)->record($patient, $data['splits']);

            return;
        }

        $sales = app(ProductSaleService::class);
        $items = filled($data['products'] ?? []) ? $sales->normalizeItems($data['products']) : [];
        $productTotal = round((float) collect($items)->sum('line_total'), 2);
        $parts = $sales->partitionPaymentRows($data['splits'], $productTotal, $data['currency'] ?? $this->record->currency);

        if ($items !== []) {
            $sales->create([
                'items' => $items,
                'payment_method' => $parts['product'][0]['payment_method'],
                'payment_rows' => $parts['product'],
                'currency' => $data['currency'] ?? $this->record->currency,
                'patient_id' => $this->record->patient_id,
                'visit_id' => $this->record->getKey(),
                'sold_at' => now(),
            ]);
        }
        $serviceAmount = Money::decimal((float) $data['amount'] - $productTotal);
        if (Money::minorUnits($serviceAmount) > 0) {
            app(PaymentProcessor::class)->process([
                'visit_id' => $this->record->getKey(), 'amount' => $serviceAmount,
                'currency' => $data['currency'] ?? $this->record->currency, 'payment_date' => now()->toDateString(),
            ], $parts['service']);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->returnToDashboard
            ? Dashboard::getUrl()
            : VisitResource::getUrl('index');
    }

    private function calculateRemainingAmount(): ?float
    {
        $payable = $this->calculateFinalPayableAmount() + $this->pendingProductTotal($this->pendingPayment['products'] ?? []);

        if ($payable === null) {
            return null;
        }

        return app(PaymentProcessor::class)->amountDue($payable, $this->getStagedPaidAmount());
    }

    private function pendingProductTotal(array $products): float
    {
        return round((float) collect($products)->sum(fn (array $item): float => max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['unit_price'] ?? 0)), 2);
    }

    private function calculateFinalPayableAmount(): ?float
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

        return round(max($totalPrice - $discountAmount, 0), 2);
    }

    /** @param array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>} $data */
    private function stagePayment(array $data): void
    {
        $this->pendingPayment = $this->preparedPendingPayment($data);
    }

    /** @param array<string, mixed> $data */
    private function preparedPendingPayment(array $data): array
    {
        $processor = app(PaymentProcessor::class);
        $prepared = $processor->prepare(
            $data['amount'] ?? null,
            $data['splits'] ?? [],
            $data['currency'] ?? $this->form->getRawState()['currency'] ?? Currency::DEFAULT,
        );
        $payable = $this->calculateFinalPayableAmount() + $this->pendingProductTotal($data['products'] ?? []);

        if ($payable === null || Money::minorUnits($prepared['amount']) > Money::minorUnits($payable)) {
            throw ValidationException::withMessages([
                'amount' => 'გადახდა საბოლოო გადასახდელ თანხას ვერ გადააჭარბებს.',
            ]);
        }

        return [
            ...$data,
            'amount' => $prepared['amount'],
            'splits' => $prepared['rows'],
        ];
    }

    /** @param array<string, mixed> $state */
    private function validatePendingPaymentAgainst(float $totalPrice, array $state): void
    {
        if ($this->pendingPayment === null) {
            return;
        }

        $discountValue = (float) ($state['discount_value'] ?? 0);
        $discountAmount = ($state['discount_type'] ?? 'amount') === 'percent'
            ? round($totalPrice * $discountValue / 100, 2)
            : $discountValue;
        $payable = max($totalPrice - $discountAmount, 0) + $this->pendingProductTotal($this->pendingPayment['products'] ?? []);

        if (Money::minorUnits($this->getStagedPaidAmount()) > Money::minorUnits($payable)) {
            throw ValidationException::withMessages([
                'pendingPayment' => 'შეტანილი გადახდა საბოლოო გადასახდელ თანხას აღემატება. გთხოვთ, შეასწოროთ გადახდა.',
            ]);
        }
    }
}
