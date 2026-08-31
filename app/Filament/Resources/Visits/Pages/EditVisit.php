<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Visit;
use App\Services\PartnerVisitPaymentRecorder;
use App\Services\PaymentProcessor;
use App\Services\ProductSaleService;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditVisit extends EditRecord
{
    protected static string $resource = VisitResource::class;

    public function getTitle(): string
    {
        return 'ვიზიტის რედაქტირება';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (Visit $record): bool => $record->payments()->exists())
                ->tooltip(fn (Visit $record): ?string => $record->payments()->exists()
                    ? 'ვიზიტის წაშლა შეუძლებელია, რადგან მას გადახდების ისტორია აქვს.'
                    : null),
        ];
    }

    /** @param array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>} $data */
    public function submitPayment(array $data): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        try {
            $patient = $this->record->patient()->with('patientGroup')->firstOrFail();
            if ($patient->isIsraelPartner()) {
                $prepared = app(PaymentProcessor::class)->prepare(
                    $data['amount'],
                    $data['splits'],
                    $data['currency'] ?? $this->record->currency,
                );
                app(PartnerVisitPaymentRecorder::class)->record($patient, $prepared['rows']);
                $this->record->refresh();
                Notification::make()->success()->title('გადახდა წარმატებით დაემატა.')->send();

                return;
            }

            app(PaymentProcessor::class)->process([
                'visit_id' => $this->record->getKey(),
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $this->record->currency,
                'payment_date' => now()->toDateString(),
            ], $data['splits']);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('გადახდა ვერ დაემატა')
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('გადახდა ვერ დაემატა')
                ->body('დაფიქსირდა ტექნიკური შეცდომა. გთხოვთ, სცადოთ ხელახლა.')
                ->send();

            throw ValidationException::withMessages([
                'amount' => 'გადახდა ვერ დაემატა. გთხოვთ, სცადოთ ხელახლა.',
            ]);
        }

        $this->record->refresh();

        Notification::make()
            ->success()
            ->title('გადახდა წარმატებით დაემატა.')
            ->send();
    }

    public function submitCombinedPayment(array $data): void
    {
        $products = $data['products'] ?? [];
        if ($products === []) {
            $this->submitPayment($data);

            return;
        }

        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
        $patient = $this->record->patient()->with('patientGroup')->firstOrFail();
        if ($patient->isIsraelPartner()) {
            $prepared = app(PaymentProcessor::class)->prepare(
                $data['amount'],
                $data['splits'],
                $data['currency'] ?? $this->record->currency,
            );
            app(PartnerVisitPaymentRecorder::class)->record($patient, $prepared['rows']);
            $this->record->refresh();
            Notification::make()->success()->title('გადახდა წარმატებით დაემატა.')->send();

            return;
        }

        $sales = app(ProductSaleService::class);
        $items = $sales->normalizeItems($products);
        $productTotal = round((float) collect($items)->sum('line_total'), 2);
        $parts = $sales->partitionPaymentRows($data['splits'], $productTotal, $data['currency'] ?? $this->record->currency);

        $sales->create([
            'items' => $items, 'payment_method' => $parts['product'][0]['payment_method'],
            'payment_rows' => $parts['product'], 'currency' => $data['currency'] ?? $this->record->currency,
            'patient_id' => $this->record->patient_id, 'visit_id' => $this->record->getKey(), 'sold_at' => now(),
        ]);
        $serviceAmount = Money::decimal((float) $data['amount'] - $productTotal);
        if (Money::minorUnits($serviceAmount) > 0) {
            app(PaymentProcessor::class)->process([
                'visit_id' => $this->record->getKey(), 'amount' => $serviceAmount,
                'currency' => $data['currency'] ?? $this->record->currency, 'payment_date' => now()->toDateString(),
            ], $parts['service']);
        }
        $this->record->refresh();
        Notification::make()->success()->title('გადახდა და პროდუქტი დამატებულია.')->send();
    }

    public function getCurrentRemainingAmount(): ?float
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
        $currency = $state['currency'] ?? $this->record->currency ?? 'GEL';
        $paidAmount = (float) $this->record->payments()->where('currency', $currency)->sum('amount');

        return app(PaymentProcessor::class)->amountDue($totalPrice - $discountAmount, $paidAmount);
    }

    public function getStagedPaidAmount(): float
    {
        $state = $this->form->getRawState();
        $currency = $state['currency'] ?? $this->record->currency ?? 'GEL';

        return round((float) $this->record->payments()->where('currency', $currency)->sum('amount'), 2);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getRawState();
        VisitForm::validatePatientTreatmentRequirement(
            $state['patient_id'] ?? null,
            (array) ($state['treatmentCaseItems'] ?? []),
        );
        $data['total_price'] = Visit::totalFromTreatmentItemState(
            $state['treatmentCaseItems'] ?? [],
            $this->record->total_price,
            ($state['visit_type'] ?? 'treatment') === 'consultation'
                ? ($state['consultation_fee'] ?? 0)
                : 0,
            $state['currency'] ?? $this->record->currency ?? 'GEL',
        );

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncTreatmentItemsTotal();
    }
}
