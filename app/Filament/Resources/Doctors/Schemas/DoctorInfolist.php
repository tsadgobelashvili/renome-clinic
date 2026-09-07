<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Services\DoctorCompensationCalculator;
use App\Services\IsraeliSalaryCarryService;
use App\Services\SalarySettlementService;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class DoctorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ექიმის ინფორმაცია')
                ->schema([
                    TextEntry::make('full_name')->label('სახელი და გვარი'),
                    TextEntry::make('phone')->label('ტელეფონი')->placeholder('—'),
                    TextEntry::make('specialty')->label('სპეციალობა')->placeholder('—'),
                    IconEntry::make('is_active')->label('აქტიური')->boolean(),
                ])->columns(2),

            Section::make('ვიზიტებისა და ფინანსების შეჯამება')
                ->schema([
                    TextEntry::make('summary_visits_count')
                        ->label('ვიზიტების რაოდენობა')
                        ->state(fn (Doctor $record): int => $record->getFinancialSummary()['visits_count']),
                    TextEntry::make('summary_gross_amount')
                        ->label('ღირებულება')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'gross_amount'))
                        ->listWithLineBreaks(),
                    TextEntry::make('summary_discount_amount')
                        ->label('ფასდაკლება')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'discount_amount'))
                        ->listWithLineBreaks(),
                    TextEntry::make('summary_paid_amount')
                        ->label('გადახდილი')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'paid_amount'))
                        ->listWithLineBreaks(),
                    TextEntry::make('summary_remaining_amount')
                        ->label('დარჩენილი')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'remaining_amount'))
                        ->listWithLineBreaks(),
                ])->columns(3),

            Section::make('ანაზღაურება')
                ->key('compensation')
                ->compact()
                ->headerActions([
                    Action::make('calculateSalary')
                        ->label('ხელფასის დათვლა')
                        ->icon('heroicon-o-calculator')
                        ->color('primary')
                        ->modalHeading(fn (Doctor $record): string => $record->full_name.' — ხელფასის დათვლა')
                        ->modalWidth(fn (Action $action): Width => ($action->getRawData()['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG
                            ? Width::FiveExtraLarge : Width::SevenExtraLarge)
                        ->modalSubmitActionLabel('ხელფასის დაფიქსირება')
                        ->extraModalWindowAttributes(fn (Action $action): array => [
                            'class' => ($action->getRawData()['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG
                                ? 'renome-israeli-salary-modal' : '',
                        ])
                        ->modalCancelActionLabel('დახურვა')
                        ->visible(function (): bool {
                            $user = auth()->user();

                            return $user?->isOwner() || $user?->isAdministrator();
                        })
                        ->fillForm(fn (Doctor $record): array => [
                            'from' => app(DoctorCompensationCalculator::class)->defaultPeriodStart(
                                $record->getKey(),
                                PatientGroup::CLINIC_SLUG,
                            ),
                            'until' => today()->toDateString(),
                            'cutoff_visit_id' => null,
                            'patient_group' => PatientGroup::CLINIC_SLUG,
                            'percentage' => (float) ($record->compensation_percentage ?? 0),
                            'payment_currency' => Currency::DEFAULT,
                            'exchange_rate' => null,
                        ])
                        ->schema([
                            Grid::make()->columns(fn (Get $get): int => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG ? 4 : 7)->schema([
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
                                Select::make('cutoff_visit_id')
                                    ->key('salary-cutoff')
                                    ->label('ვიზიტის ჩათვლით')
                                    ->visible(fn (Get $get): bool => $get('patient_group') !== PatientGroup::ISRAEL_PARTNER_SLUG)
                                    ->placeholder('დღის ბოლომდე')
                                    ->native(false)
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (?string $search, Get $get, Doctor $record): array => filled($get('from')) && filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitOptions(
                                            $record->getKey(),
                                            $get('from'),
                                            $get('until'),
                                            $search,
                                            $get('patient_group') ?: PatientGroup::CLINIC_SLUG,
                                        )
                                        : [])
                                    ->getOptionLabelUsing(fn (mixed $value, Get $get, Doctor $record): ?string => filled($value) && filled($get('from')) && filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitLabel(
                                            $record->getKey(),
                                            $get('from'),
                                            $get('until'),
                                            (int) $value,
                                            $get('patient_group') ?: PatientGroup::CLINIC_SLUG,
                                        )
                                        : null)
                                    ->live(),
                                TextInput::make('percentage')->label('ექიმის %')->numeric()->required()
                                    ->hidden(fn (Get $get): bool => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                                    ->dehydratedWhenHidden()
                                    ->minValue(0.01)->maxValue(100)->step(0.01)->suffix('%')->live(debounce: 300),
                                Select::make('payment_currency')
                                    ->label('გადახდის ვალუტა')
                                    ->options(['GEL' => 'GEL', 'USD' => 'USD'])
                                    ->default(Currency::DEFAULT)
                                    ->required()
                                    ->live()
                                    ->visible(fn (Get $get): bool => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG),
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
                                Grid::make(5)->columnSpanFull()
                                    ->extraAttributes(fn (Get $get): array => ['class' => 'renome-salary-payout-summary '.($get('payment_currency') === 'USD' ? 'renome-salary-summary-usd' : 'renome-salary-summary-gel')])
                                    ->visible(fn (Get $get): bool => $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG)
                                    ->schema([
                                        View::make('filament.resources.doctors.israeli-salary-value')
                                            ->viewData(fn (Get $get, Doctor $record): array => [
                                                'label' => 'ხელფასი',
                                                'value' => Currency::format((float) (self::salaryReport($record, $get)['totals']['GEL']['doctor_share'] ?? 0), 'GEL'),
                                            ]),
                                        TextInput::make('exchange_rate')->label('კურსი')
                                            ->numeric()->minValue(0.000001)->step(0.000001)->live(debounce: 300)
                                            ->required()->visible(fn (Get $get): bool => $get('payment_currency') === 'USD'),
                                        View::make('filament.resources.doctors.israeli-salary-value')
                                            ->viewData(fn (Get $get, Doctor $record): array => [
                                                'label' => $get('payment_currency') === 'USD' ? 'გასაცემი' : 'გაცემული',
                                                'value' => self::compactPayout($record, $get)['calculated'],
                                            ]),
                                        TextInput::make('actual_paid_usd')->label('გაცემული')->prefix('$')
                                            ->numeric()->minValue(0)->maxValue(999999999999.99)->step(0.01)
                                            ->placeholder(fn (Get $get, Doctor $record): string => self::compactPayout($record, $get)['placeholder'])
                                            ->extraInputAttributes(fn (Get $get): array => [
                                                'class' => (float) $get('actual_paid_usd') > 0 ? 'renome-salary-paid-positive' : 'renome-salary-paid-muted',
                                            ])
                                            ->live(debounce: 300)->visible(fn (Get $get): bool => $get('payment_currency') === 'USD'),
                                        View::make('filament.resources.doctors.israeli-salary-value')
                                            ->visible(fn (Get $get): bool => $get('payment_currency') === 'USD')
                                            ->viewData(fn (Get $get, Doctor $record): array => [
                                                'label' => 'სხვაობა',
                                                'value' => self::compactPayout($record, $get)['difference'],
                                            ]),
                                    ]),
                                View::make('filament.resources.doctors.salary-calculation-modal')
                                    ->key('salary-report')
                                    ->visible(fn (Get $get): bool => $get('patient_group') !== PatientGroup::ISRAEL_PARTNER_SLUG)
                                    ->columnSpanFull()
                                    ->viewData(fn (Get $get, Doctor $record): array => [
                                        'report' => self::salaryReport($record, $get),
                                        'cutoffVisitId' => filled($get('cutoff_visit_id')) ? (int) $get('cutoff_visit_id') : null,
                                        'lastSettled' => auth()->user()?->isOwner() ? $record->getCompensationSummary() : [],
                                        'ownerSplitEligible' => $record->isOwnerSplitDoctor(),
                                        'paymentCurrency' => $get('payment_currency') ?: Currency::DEFAULT,
                                        'doctorId' => $record->getKey(),
                                        'actualPaidUsd' => is_numeric($get('actual_paid_usd')) ? (float) $get('actual_paid_usd') : null,
                                        'exchangeRate' => is_numeric($get('exchange_rate')) ? (float) $get('exchange_rate') : null,
                                    ]),
                            ]),
                        ])
                        ->action(function (Doctor $record, array $data, SalarySettlementService $service): void {
                            $user = auth()->user();
                            abort_unless($user?->isOwner() || $user?->isAdministrator(), 403);

                            $service->settle(
                                $record->getKey(),
                                $data['from'],
                                $data['until'],
                                (float) $data['percentage'],
                                auth()->id(),
                                filled($data['cutoff_visit_id'] ?? null) ? (int) $data['cutoff_visit_id'] : null,
                                $data['patient_group'] ?? PatientGroup::CLINIC_SLUG,
                                $data['payment_currency'] ?? null,
                                is_numeric($data['exchange_rate'] ?? null) ? (float) $data['exchange_rate'] : null,
                                ($data['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG && ($data['payment_currency'] ?? null) === 'USD'
                                    && is_numeric($data['actual_paid_usd'] ?? null) ? (float) $data['actual_paid_usd'] : null,
                                ($data['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG
                                    ? ($data['selected_lab_work_ids'] ?? []) : null,
                                ($data['patient_group'] ?? null) === PatientGroup::ISRAEL_PARTNER_SLUG,
                            );
                            $record->clearCompensationSummaryCache();
                            Notification::make()->success()->title('ხელფასი დაფიქსირდა.')->send();
                        }),
                    Action::make('salaryHistory')->label('ხელფასების ისტორია')->color('gray')
                        ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                        ->url(fn (Doctor $record): string => DoctorCompensation::getUrl(['doctor' => $record->getKey()]).'#history'),
                ])
                ->schema([
                    View::make('filament.resources.doctors.compensation-summary')
                        ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                        ->viewData(fn (Doctor $record): array => [
                            'summary' => $record->getCompensationSummary(),
                        ]),
                ]),
        ]);
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
        $set('actual_paid_usd', null);
        $set('selected_lab_work_ids', $get('patient_group') === PatientGroup::ISRAEL_PARTNER_SLUG
            ? array_map('strval', array_keys(self::labOptions($doctor, $get))) : []);
    }

    private static function compactPayout(Doctor $doctor, Get $get): array
    {
        $salary = (float) (self::salaryReport($doctor, $get)['totals']['GEL']['doctor_share'] ?? 0);
        $empty = ['calculated' => '—', 'placeholder' => '', 'difference' => '—'];
        if ($get('payment_currency') !== 'USD') {
            return [...$empty, 'calculated' => Currency::format($salary, 'GEL')];
        }
        $rate = (float) $get('exchange_rate');
        if ($rate <= 0) {
            return $empty;
        }
        $actual = $get('actual_paid_usd');
        $payout = app(IsraeliSalaryCarryService::class)->preview(
            $doctor->getKey(), round($salary / $rate, 2),
            is_numeric($actual) && $actual >= 0 && $actual <= 999999999999.99 ? (float) $actual : null,
        );
        $difference = $payout['difference_usd'];

        return [
            'calculated' => Currency::format($payout['calculated_usd'], 'USD'),
            'placeholder' => number_format($payout['calculated_usd'], 2, '.', ''),
            'difference' => ($difference > 0 ? '+' : '').Currency::format(abs($difference), 'USD')
                .($difference > 0 ? ' ავანსი' : ($difference < 0 ? ' დარჩა' : '')),
        ];
    }
}
