<?php

namespace App\Filament\Actions;

use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Services\DoctorCompensationCalculator;
use App\Services\IsraeliLabSalaryItems;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class DoctorSalaryAction
{
    public static function make(bool $standalone = false): Action
    {
        $action = Action::make('calculateSalary')
            ->label('ხელფასის დათვლა')
            ->icon('heroicon-o-calculator')
            ->color('primary')
            ->modalHeading(fn (Doctor $record): string => $record->full_name.' — ხელფასის დათვლა')
            ->modalWidth(fn (Action $action): Width => ($action->getRawData()['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG
                ? Width::FiveExtraLarge : Width::SevenExtraLarge)
            ->modalSubmitActionLabel(fn (Action $action): string => ($action->getRawData()['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG ? __('salary-payout.confirm') : 'ხელფასის დაფიქსირება')
            ->extraModalWindowAttributes(fn (Action $action): array => [
                'class' => ($action->getRawData()['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG
                    ? 'renome-israeli-salary-modal' : '',
            ])
            ->modalCancelActionLabel('დახურვა')
            ->visible(function (): bool {
                $user = auth()->user();

                return $user?->isOwner() || $user?->isAdministrator();
            })
            ->fillForm(function (Doctor $record, array $arguments, $livewire): array {
                $livewire->prepareDoctorSalary($record->getKey());

                return self::initialData($record, $arguments);
            })
            ->schema([
                Grid::make()->columns(4)->schema([
                    Select::make('patient_group')
                        ->label('წყარო')
                        ->options([
                            PatientGroup::CLINIC_SLUG => 'Clinic',
                            PatientGroup::ISRAEL_PARTNER_SLUG => 'Israeli',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get, Doctor $record): void {
                            $group = $state ?: PatientGroup::CLINIC_SLUG;
                            $set('cutoff_visit_id', null);
                            $set('from', app(DoctorCompensationCalculator::class)
                                ->defaultPeriodStart($record->getKey(), $group));
                            self::resetLabSelection($record, $get, $set);
                            if ($group === PatientGroup::ISRAEL_PARTNER_SLUG) {
                                $set('allocations', [SalaryAllocationFields::defaultRow((float) (self::salaryReport($record, $get)['totals']['GEL']['doctor_share'] ?? 0))]);
                            }
                        }),
                    DatePicker::make('from')
                        ->suffixAction(Action::make('open_from_calendar')->label('კალენდარი')->icon('heroicon-o-calendar-days')
                            ->alpineClickHandler("\$el.closest('.fi-input-wrp').querySelector('.fi-fo-date-time-picker-trigger')?.click()"))
                        ->label(fn (Get $get): string => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG ? 'დან' : 'პერიოდის დასაწყისი')->required()
                        ->native(false)
                        ->closeOnDateSelection()
                        ->displayFormat('d.m.Y')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get, Doctor $record) => self::resetLabSelection($record, $get, $set)),
                    DatePicker::make('until')
                        ->suffixAction(Action::make('open_until_calendar')->label('კალენდარი')->icon('heroicon-o-calendar-days')
                            ->alpineClickHandler("\$el.closest('.fi-input-wrp').querySelector('.fi-fo-date-time-picker-trigger')?.click()"))
                        ->label(fn (Get $get): string => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG ? 'მდე' : 'პერიოდის ბოლო')->required()
                        ->native(false)
                        ->closeOnDateSelection()
                        ->displayFormat('d.m.Y')->afterOrEqual('from')->live()
                        ->afterStateUpdated(fn (Set $set, Get $get, Doctor $record) => self::resetLabSelection($record, $get, $set)),
                    // Finalization snapshots exact included item IDs, including same-day visits.
                    Hidden::make('cutoff_visit_id'),
                    Hidden::make('approved_full_discount_item_ids')->default([])->rules(['array']),
                    TextInput::make('percentage')->label('ექიმის %')->numeric()->required()
                        ->hidden(fn (Get $get): bool => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                        ->dehydratedWhenHidden()
                        ->minValue(fn (Get $get): float => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG ? 0 : 0.01)->maxValue(100)->step(0.01)->suffix('%')->live(debounce: 300),
                    TextEntry::make('clinic_payment_method')->label(__('clinic-payroll.doctor_method'))
                        ->state(fn (Doctor $record) => __('employees.payroll.'.($record->clinic_salary_payment_method ?? 'bank_transfer')))
                        ->visible(fn (Get $get) => $get('patient_group') === PatientGroup::CLINIC_SLUG),
                    CheckboxList::make('selected_lab_work_ids')
                        ->label('ლაბორატორიული სამუშაოები')
                        ->options(fn (Get $get, Doctor $record): array => self::labOptions($record, $get))
                        ->view('filament.resources.doctors.israeli-salary-items')
                        ->viewData(fn (Get $get, Doctor $record): array => [
                            'rows' => self::salaryReport($record, $get, true)['details'],
                        ])
                        ->columns(1)->columnSpanFull()->live()
                        ->visible(fn (Get $get): bool => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                        ->required()->minItems(1),
                    Grid::make(1)->columnSpanFull()
                        ->visible(fn (Get $get) => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                        ->schema(SalaryAllocationFields::make(fn (Get $get, Doctor $record) => (float) (self::salaryReport($record, $get)['totals']['GEL']['doctor_share'] ?? 0))),
                    View::make('filament.resources.doctors.salary-outstanding')->columnSpanFull()
                        ->visible(fn (Get $get) => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                        ->viewData(fn (Doctor $record) => ['settlements' => SalarySettlement::query()->unpaidAllocations()->where('doctor_id', $record->id)->with('payouts.allocations')->get()]),
                    View::make('filament.resources.doctors.salary-calculation-modal')
                        ->key('salary-report')
                        ->visible(fn (Get $get): bool => $get('patient_group') !== PatientGroup::ISRAEL_PARTNER_SLUG)
                        ->columnSpanFull()
                        ->viewData(fn (Get $get, Doctor $record, View $component): array => [
                            'report' => self::salaryReport($record, $get),
                            'approvalStatePath' => $component->getContainer()->getStatePath().'.approved_full_discount_item_ids',
                            'cutoffVisitId' => filled($get('cutoff_visit_id')) ? (int) $get('cutoff_visit_id') : null,
                            'lastSettled' => [],
                            'ownerSplitEligible' => $record->isOwnerSplitDoctor(),
                            'paymentCurrency' => $get('payment_currency') ?: Currency::DEFAULT,
                            'doctorId' => $record->getKey(),
                            'actualPaidUsd' => is_numeric($get('actual_paid_usd')) ? (float) $get('actual_paid_usd') : null,
                            'exchangeRate' => is_numeric($get('exchange_rate')) ? (float) $get('exchange_rate') : null,
                        ]),
                    View::make('filament.resources.doctors.salary-history')
                        ->columnSpanFull()
                        ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                        ->viewData(fn (Doctor $record, $livewire): array => [
                            'doctorId' => $record->getKey(),
                            'historyVisible' => $livewire->isDoctorSalaryHistoryVisible($record->getKey()),
                            'settlements' => $livewire->doctorSalaryHistory($record->getKey()),
                        ]),
                ]),
            ])
            ->action(function (Doctor $record, array $data, SalarySettlementService $service): void {
                $user = auth()->user();
                abort_unless($user?->isOwner() || $user?->isAdministrator(), 403);

                if (($data['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG) {
                    app(IsraeliSalaryPayoutService::class)->finalizeAndPay($record->id, $data['from'], $data['until'],
                        $data['selected_lab_work_ids'] ?? [], $data['allocations'] ?? [], $data['payout_request_key'], $user);
                    $record->clearCompensationSummaryCache();
                    Notification::make()->success()->title(__('salary-payout.saved'))->send();

                    return;
                }
                $service->settle(
                    $record->getKey(),
                    $data['from'],
                    $data['until'],
                    (float) $data['percentage'],
                    auth()->id(),
                    filled($data['cutoff_visit_id'] ?? null) ? (int) $data['cutoff_visit_id'] : null,
                    $data['patient_group'] ?? PatientGroup::CLINIC_SLUG,
                    approvedFullDiscountItemIds: $data['approved_full_discount_item_ids'] ?? [],
                );
                $record->clearCompensationSummaryCache();
                Notification::make()->success()->title('ხელფასი დაფიქსირდა.')->send();
            });

        if ($standalone) {
            $action
                ->model(Doctor::class)
                ->record(fn ($livewire): ?Doctor => filled($livewire->activeSalaryDoctorId)
                    ? Doctor::query()->findOrFail((int) $livewire->activeSalaryDoctorId)
                    : null)
                ->extraAttributes(['class' => 'hidden']);
        }

        return $action;
    }

    /** @return array<string, mixed> */
    private static function initialData(Doctor $doctor, array $arguments): array
    {
        $patientGroup = ($arguments['source'] ?? null) === 'israeli'
            ? PatientGroup::ISRAEL_PARTNER_SLUG
            : PatientGroup::CLINIC_SLUG;
        $from = $arguments['from'] ?? app(DoctorCompensationCalculator::class)
            ->defaultPeriodStart($doctor->getKey(), $patientGroup);
        $until = $arguments['until'] ?? today()->toDateString();
        $percentage = (float) ($doctor->compensation_percentage ?? 0);
        $selectedLabWorkIds = [];

        if ($patientGroup === PatientGroup::ISRAEL_PARTNER_SLUG) {
            $selectedLabWorkIds = app(IsraeliLabSalaryItems::class)
                ->eligible($doctor, $from, $until)
                ->modelKeys();
            $selectedLabWorkIds = array_map('strval', $selectedLabWorkIds);
        }

        return [
            'from' => $from,
            'until' => $until,
            'cutoff_visit_id' => null,
            'patient_group' => $patientGroup,
            'percentage' => $percentage,
            'payout_request_key' => (string) Str::uuid(),
            'allocations' => [['currency' => 'USD', 'source' => 'israeli', 'amount' => null, 'exchange_rate' => null]],
            'selected_lab_work_ids' => $selectedLabWorkIds,
            'approved_full_discount_item_ids' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function salaryReport(Doctor $doctor, Get $get, bool $allLabItems = false): array
    {
        $from = $get('from');
        $until = $get('until');
        $percentage = $get('percentage');

        if (blank($from) || blank($until) || $until < $from) {
            return ['totals' => [], 'details' => [], 'percentage' => 0.0];
        }

        $previewPercentage = is_numeric($percentage) ? (float) $percentage : 0.0;

        if ($previewPercentage < 0 || $previewPercentage > 100) {
            return ['totals' => [], 'details' => [], 'percentage' => $previewPercentage];
        }

        return app(DoctorCompensationCalculator::class)->calculate(
            $doctor->getKey(),
            $from,
            $until,
            $previewPercentage,
            filled($get('cutoff_visit_id')) ? (int) $get('cutoff_visit_id') : null,
            $get('patient_group') ?: PatientGroup::CLINIC_SLUG,
            $allLabItems ? null : ($get('selected_lab_work_ids') ?? []),
            $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG,
            $get('approved_full_discount_item_ids') ?? [],
        );
    }

    private static function labOptions(Doctor $doctor, Get $get): array
    {
        return collect(self::salaryReport($doctor, $get, true)['details'])
            ->where('source_type', 'lab')->mapWithKeys(fn (array $row): array => [
                $row['items'][0]['id'] => $row['patient'].' — '.$row['items'][0]['name'].' ×'.$row['items'][0]['quantity']
                    .' — '.Currency::format($row['doctor_share'], $row['currency'])
                    .' · '.$row['visit_date'].' · Lab #'.$row['lab_case_id'],
            ])->all();
    }

    private static function resetLabSelection(Doctor $doctor, Get $get, Set $set): void
    {
        $set('cutoff_visit_id', null);
        $set('approved_full_discount_item_ids', []);
        $set('selected_lab_work_ids', $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG
            ? array_map('strval', array_keys(self::labOptions($doctor, $get))) : []);
    }
}
