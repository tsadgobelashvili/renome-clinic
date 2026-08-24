<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Payment;
use App\Models\Visit;
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
            Payment::createWithSplits([
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

        return round(max(0, $totalPrice - $discountAmount - $paidAmount), 2);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getRawState();
        $data['total_price'] = Visit::totalFromTreatmentItemState(
            $state['treatmentCaseItems'] ?? [],
            $this->record->total_price,
            ($state['visit_type'] ?? 'treatment') === 'consultation'
                ? ($state['consultation_fee'] ?? 0)
                : 0,
        );

        return $data;
    }

    public function openTreatmentEstimate(): void
    {
        $this->save(shouldRedirect: false);

        $estimate = $this->record->treatmentEstimates()->latest('id')->first();
        $url = $estimate
            ? TreatmentEstimateResource::getUrl('edit', ['record' => $estimate])
            : TreatmentEstimateResource::getUrl('create', [
                'visit_id' => $this->record->getKey(),
                'patient_id' => $this->record->patient_id,
                'doctor_id' => $this->record->doctor_id,
            ]);

        $this->redirect($url);
    }

    protected function afterSave(): void
    {
        $this->record->syncTreatmentItemsTotal();
    }
}
