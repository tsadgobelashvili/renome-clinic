<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Payment;
use App\Models\Visit;
use App\Services\PartnerVisitPaymentRecorder;
use App\Services\PaymentProcessor;
use App\Services\ProductSaleService;
use App\Services\VisitCancellationService;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditVisit extends EditRecord
{
    protected static string $resource = VisitResource::class;

    protected function getRedirectUrl(): string
    {
        return Dashboard::getUrl();
    }

    public function getTitle(): string
    {
        return 'ვიზიტის რედაქტირება';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        if (! $this->record->is_cancelled) {
            return 'ვიზიტის ტიპი: '.$this->record->type_label;
        }

        $details = $this->record->cancelled_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
        $reason = filled($this->record->cancellation_reason) ? ' — '.$this->record->cancellation_reason : '';

        return 'გაუქმებული ვიზიტი'.($details ? ' · '.$details : '').$reason;
    }

    public function removeConsultationAction(): Action
    {
        return Action::make('removeConsultation')
            ->label(app()->getLocale() === 'en' ? 'Remove consultation' : 'კონსულტაციის მოხსნა')
            ->color('danger')->outlined()->size('sm')
            ->icon('heroicon-o-minus-circle')
            ->visible(fn (): bool => $this->record->visit_type === 'consultation' && Gate::allows('update', $this->record))
            ->requiresConfirmation()
            ->modalHeading(app()->getLocale() === 'en' ? 'Remove consultation' : 'კონსულტაციის მოხსნა')
            ->modalDescription('მოიხსნება მხოლოდ კონსულტაციის კლასიფიკაცია. ვიზიტი, პროცედურები, თანხები და გადახდები უცვლელი დარჩება.')
            ->action(function (): void {
                Gate::authorize('update', $this->record);
                DB::transaction(function (): void {
                    $visit = Visit::query()->lockForUpdate()->findOrFail($this->record->getKey());
                    if ($visit->visit_type !== 'consultation') {
                        return;
                    }

                    $items = $visit->treatmentCaseItems()->with('treatmentCase')->get();
                    $type = $items->isNotEmpty() && $items->every(fn ($item): bool => $item->treatmentCase?->category === 'tomography')
                        ? 'diagnostic'
                        : 'treatment';

                    // Classification correction only: normal saving hooks clear fees and
                    // recalculate discounts. Preserve all financial/history fields here.
                    Visit::query()->whereKey($visit->getKey())->update(['visit_type' => $type]);
                });
                Notification::make()->success()->title('კონსულტაციის კლასიფიკაცია მოხსნილია.')->send();
                $this->redirect(VisitResource::getUrl('edit', ['record' => $this->record]));
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancelVisit')
                ->label('ვიზიტის გაუქმება')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => (auth()->user()?->isOwner() ?? false) && ! $this->record->is_cancelled)
                ->requiresConfirmation()
                ->modalHeading('ვიზიტის გაუქმება')
                ->modalDescription('ვიზიტი დარჩება ისტორიაში, ხოლო მისი გადახდები და აქტიური ფინანსური და სტატისტიკური ეფექტები გაუქმდება.')
                ->modalSubmitActionLabel('ვიზიტის გაუქმება')
                ->schema([
                    Textarea::make('reason')->label('გაუქმების მიზეზი')->maxLength(500)->rows(3),
                ])
                ->action(function (array $data, VisitCancellationService $service): void {
                    abort_unless(auth()->user()?->isOwner(), 403);
                    $this->record = $service->cancel($this->record, auth()->user(), $data['reason'] ?? null);
                    Notification::make()->success()->title('ვიზიტი გაუქმებულია.')->send();
                    $this->redirect(VisitResource::getUrl('edit', ['record' => $this->record]));
                }),
            Action::make('editHistoricalPayment')
                ->label('რედაქტირება')
                ->extraAttributes(['class' => 'hidden'])
                ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                ->modalHeading('გადახდის რედაქტირება')
                ->modalSubmitActionLabel('შენახვა')
                ->modalCancelActionLabel('გაუქმება')
                ->fillForm(function (array $arguments): array {
                    abort_unless(auth()->user()?->isOwner(), 403);
                    $payment = $this->paymentForCorrection($arguments);

                    return [
                        'payment_date' => $payment->payment_date?->toDateString(),
                        'splits' => $payment->splits->map->only([
                            'payment_method', 'currency', 'amount', 'exchange_rate',
                        ])->all(),
                    ];
                })
                ->schema([
                    DatePicker::make('payment_date')->label('გადახდის თარიღი')->required(),
                    Repeater::make('splits')->label('გადახდის ნაწილები')->minItems(1)->required()->schema([
                        Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())->required()->native(false),
                        Select::make('currency')->label('ვალუტა')->options(array_combine(
                            array_keys(Currency::OPTIONS),
                            array_keys(Currency::OPTIONS),
                        ))->required()->native(false)->live(),
                        TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->required(),
                        TextInput::make('exchange_rate')->label('USD კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                            ->visible(fn ($get): bool => $get('currency') === 'USD')
                            ->required(fn ($get): bool => $get('currency') === 'USD'),
                    ])->columns(4)->reorderable(false),
                ])
                ->action(function (array $data, array $arguments, PaymentProcessor $processor): void {
                    abort_unless(auth()->user()?->isOwner(), 403);
                    $processor->correct(
                        $this->paymentForCorrection($arguments),
                        ['payment_date' => $data['payment_date']],
                        $data['splits'],
                    );
                    $this->refreshPaymentHistory();
                    Notification::make()->success()->title('გადახდა განახლდა.')->send();
                }),
            Action::make('voidHistoricalPayment')
                ->label('გაუქმება')
                ->extraAttributes(['class' => 'hidden'])
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                ->requiresConfirmation()
                ->modalHeading('გადახდის გაუქმება')
                ->modalDescription('გადახდა დარჩება ისტორიაში, მაგრამ აღარ ჩაითვლება ვიზიტის, სალაროსა და ფინანსების აქტიურ თანხებში.')
                ->modalSubmitActionLabel('გადახდის გაუქმება')
                ->action(function (array $arguments, PaymentProcessor $processor): void {
                    abort_unless(auth()->user()?->isOwner(), 403);
                    $processor->void($this->paymentForCorrection($arguments));
                    $this->refreshPaymentHistory();
                    Notification::make()->success()->title('გადახდა გაუქმებულია.')->send();
                }),
            DeleteAction::make()
                ->disabled(fn (Visit $record): bool => $record->is_cancelled || $record->payments()->exists())
                ->tooltip(fn (Visit $record): ?string => $record->payments()->exists()
                    ? 'ვიზიტის წაშლა შეუძლებელია, რადგან მას გადახდების ისტორია აქვს.'
                    : null),
        ];
    }

    private function paymentForCorrection(array $arguments): Payment
    {
        return $this->record->payments()->with('splits')->findOrFail($arguments['payment'] ?? null);
    }

    private function refreshPaymentHistory(): void
    {
        $this->record->refresh();
        $this->dispatch('$refresh');
    }

    /** @param array{amount: mixed, splits: array<int, array{payment_method: string, amount: mixed}>} $data */
    public function submitPayment(array $data): void
    {
        abort_if($this->record->is_cancelled, 422, 'Cancelled visits cannot receive payments.');

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
        VisitForm::validateVisitTypeItems((string) ($state['visit_type'] ?? 'treatment'), (array) ($state['treatmentCaseItems'] ?? []), 'data.treatmentCaseItems');
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
