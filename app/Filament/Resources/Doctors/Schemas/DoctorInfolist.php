<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use App\Support\Currency;
use Filament\Actions\Action;
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
                        ->modalWidth(Width::SevenExtraLarge)
                        ->modalSubmitActionLabel('ხელფასის დაფიქსირება')
                        ->modalCancelActionLabel('დახურვა')
                        ->fillForm(fn (Doctor $record): array => [
                            'from' => app(DoctorCompensationCalculator::class)->defaultPeriodStart($record->getKey()),
                            'until' => today()->toDateString(),
                            'cutoff_visit_id' => null,
                            'patient_group' => DoctorCompensationCalculator::GROUP_ALL,
                            'percentage' => (float) ($record->compensation_percentage ?? 0),
                        ])
                        ->schema([
                            Grid::make(5)->schema([
                                Select::make('patient_group')
                                    ->label('პაციენტის ჯგუფი')
                                    ->options([
                                        DoctorCompensationCalculator::GROUP_ALL => 'ყველა',
                                        PatientGroup::CLINIC_SLUG => 'Clinic',
                                        PatientGroup::ISRAEL_PARTNER_SLUG => 'Israel Partner',
                                    ])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('cutoff_visit_id', null)),
                                DatePicker::make('from')->label('პერიოდის დასაწყისი')->required()
                                    ->displayFormat('d.m.Y')->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('cutoff_visit_id', null)),
                                DatePicker::make('until')->label('პერიოდის ბოლო')->required()
                                    ->displayFormat('d.m.Y')->afterOrEqual('from')->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('cutoff_visit_id', null)),
                                Select::make('cutoff_visit_id')
                                    ->key('salary-cutoff')
                                    ->label('ვიზიტის ჩათვლით')
                                    ->placeholder('დღის ბოლომდე')
                                    ->native(false)
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (?string $search, Get $get, Doctor $record): array => filled($get('from')) && filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitOptions(
                                            $record->getKey(),
                                            $get('from'),
                                            $get('until'),
                                            $search,
                                            $get('patient_group') ?: DoctorCompensationCalculator::GROUP_ALL,
                                        )
                                        : [])
                                    ->getOptionLabelUsing(fn (mixed $value, Get $get, Doctor $record): ?string => filled($value) && filled($get('from')) && filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitLabel(
                                            $record->getKey(),
                                            $get('from'),
                                            $get('until'),
                                            (int) $value,
                                            $get('patient_group') ?: DoctorCompensationCalculator::GROUP_ALL,
                                        )
                                        : null)
                                    ->live(),
                                TextInput::make('percentage')->label('ექიმის %')->numeric()->required()
                                    ->minValue(0.01)->maxValue(100)->step(0.01)->suffix('%')->live(debounce: 300),
                                View::make('filament.resources.doctors.salary-calculation-modal')
                                    ->key('salary-report')
                                    ->columnSpanFull()
                                    ->viewData(fn (Get $get, Doctor $record): array => [
                                        'report' => self::salaryReport($record, $get),
                                        'cutoffVisitId' => filled($get('cutoff_visit_id')) ? (int) $get('cutoff_visit_id') : null,
                                        'lastSettled' => auth()->user()?->isOwner() ? $record->getCompensationSummary() : [],
                                        'ownerSplitEligible' => $record->isOwnerSplitDoctor(),
                                    ]),
                            ]),
                        ])
                        ->action(function (Doctor $record, array $data, SalarySettlementService $service): void {
                            $service->settle(
                                $record->getKey(),
                                $data['from'],
                                $data['until'],
                                (float) $data['percentage'],
                                auth()->id(),
                                filled($data['cutoff_visit_id'] ?? null) ? (int) $data['cutoff_visit_id'] : null,
                                $data['patient_group'] ?? DoctorCompensationCalculator::GROUP_ALL,
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
    private static function salaryReport(Doctor $doctor, Get $get): array
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
            $get('patient_group') ?: DoctorCompensationCalculator::GROUP_ALL,
        );
    }
}
