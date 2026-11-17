<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Services\IsraeliPatientPaymentLifecycle;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IsraeliPaymentDetailsAction
{
    public static function make(): Action
    {
        $resolve = function (Model $record): PartnerPatientPayment {
            $id = $record instanceof PartnerFinanceEntry ? $record->source_id : -$record->getKey();
            abort_unless($record instanceof PartnerFinanceEntry ? $record->source_type === 'payment' : $record->source === 'israeli', 404);
            $payment = PartnerPatientPayment::withTrashed()->findOrFail($id);
            Gate::authorize('view', $payment);

            return $payment;
        };

        return Action::make('patientPaymentDetails')->label(__('israeli-payments.details'))
            ->authorize(fn (): bool => Gate::allows('viewAny', PartnerPatientPayment::class))
            ->visible(fn (Model $record): bool => $record instanceof PartnerFinanceEntry ? $record->source_type === 'payment' : $record->source === 'israeli')
            ->modalHeading(__('israeli-payments.details'))->modalWidth('3xl')->modalSubmitAction(false)
            ->modalContent(fn (Model $record) => self::view($resolve($record)))
            ->extraModalFooterActions(fn (Model $record) => self::footer($resolve($record)));
    }

    public static function view(PartnerPatientPayment $payment): \Illuminate\Contracts\View\View
    {
        Gate::authorize('view', $payment);

        return view('filament.resources.partner-finance.payment-details', ['payment' => $payment,
            'reason' => app(IsraeliPatientPaymentLifecycle::class)->protectionReason($payment),
            'audits' => $payment->audits()->get()]);
    }

    public static function footer(PartnerPatientPayment $payment): array
    {
        $service = app(IsraeliPatientPaymentLifecycle::class);
        if (! Gate::allows('update', $payment) || $service->protectionReason($payment)) {
            return [];
        }

        return [
            Action::make('editPayment')->label(__('israeli-payments.edit'))->authorize(fn () => Gate::allows('update', $payment))
                ->modalHeading(__('israeli-payments.edit'))->schema(self::fields())
                ->fillForm(fn () => [...$payment->attributesToArray(), 'expected_version' => $payment->lock_version,
                    'operation_key' => (string) Str::uuid(), 'account' => $payment->payment_method === 'cash' ? 'cash' : 'bank'])
                ->action(function (array $data, $livewire) use ($payment, $service): void {
                    self::submit(fn () => $service->update($payment->id, $data), $livewire);
                })->cancelParentActions(),
            Action::make('cancelPayment')->label(__('israeli-payments.cancel'))->color('danger')
                ->authorize(fn () => Gate::allows('delete', $payment))->requiresConfirmation()
                ->modalHeading(__('israeli-payments.cancel'))->schema([
                    Hidden::make('expected_version')->default($payment->lock_version)->required(),
                    Hidden::make('operation_key')->default(fn () => (string) Str::uuid())->required(),
                    Textarea::make('reason')->label(__('israeli-payments.reason'))->required()->maxLength(500),
                ])->action(function (array $data, $livewire) use ($payment, $service): void {
                    self::submit(fn () => $service->cancel($payment->id, $data), $livewire);
                })->cancelParentActions(),
        ];
    }

    public static function fields(): array
    {
        return [
            Hidden::make('expected_version')->required(), Hidden::make('operation_key')->required(),
            Select::make('patient_id')->label(__('israeli-payments.patient'))->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Patient::where('patient_group_id', PatientGroup::israelPartnerId())
                    ->where(fn ($q) => $q->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                    ->limit(30)->get()->mapWithKeys(fn ($patient) => [$patient->id => $patient->full_name])->all())
                ->getOptionLabelUsing(fn ($value) => Patient::where('patient_group_id', PatientGroup::israelPartnerId())->find($value)?->full_name),
            TextInput::make('amount')->label(__('israeli-payments.amount'))->numeric()->minValue(0.01)->step(0.01)->required(),
            Select::make('currency')->label(__('israeli-payments.currency'))->options(['GEL' => 'GEL', 'USD' => 'USD'])->required(),
            DateTimePicker::make('paid_at')->label(__('israeli-payments.date'))->timezone(config('app.timezone'))->displayFormat('d.m.Y H:i')->required(),
            Select::make('payment_method')->label(__('israeli-payments.method'))->options(PaymentMethod::options())->required()->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set('account', $state === 'cash' ? 'cash' : 'bank')),
            Select::make('account')->label(__('israeli-payments.account'))->options(['cash' => __('israeli-payments.cash'), 'bank' => __('israeli-payments.bank')])
                ->required()->live()->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    if ($state === 'cash') {
                        $set('payment_method', 'cash');
                    } elseif ($get('payment_method') === 'cash') {
                        $set('payment_method', 'bank_transfer');
                    }
                }),
            Textarea::make('notes')->label(__('israeli-payments.notes'))->maxLength(500),
        ];
    }

    private static function submit(\Closure $operation, $livewire): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.$field => $messages])->all());
        }
        if (method_exists($livewire, 'flushCachedTableRecords')) {
            $livewire->flushCachedTableRecords();
        }
        $livewire->dispatch('refreshPatientProfile');
        Notification::make()->success()->title(__('israeli-payments.saved'))->send();
    }
}
