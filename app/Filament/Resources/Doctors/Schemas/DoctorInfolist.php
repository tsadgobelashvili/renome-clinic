<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
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
                            'percentage' => (float) ($record->compensation_percentage ?? 0),
                        ])
                        ->schema([
                            Grid::make(4)->schema([
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
                                    ->getSearchResultsUsing(fn (?string $search, Get $get, Doctor $record): array => filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitOptions(
                                            $record->getKey(),
                                            $get('until'),
                                            $search,
                                        )
                                        : [])
                                    ->getOptionLabelUsing(fn (mixed $value, Get $get, Doctor $record): ?string => filled($value) && filled($get('until'))
                                        ? app(DoctorCompensationCalculator::class)->cutoffVisitLabel(
                                            $record->getKey(),
                                            $get('until'),
                                            (int) $value,
                                        )
                                        : null)
                                    ->live(),
                                TextInput::make('percentage')->label('ექიმის %')->numeric()->required()
                                    ->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->live(debounce: 300),
                                View::make('filament.resources.doctors.salary-calculation-modal')
                                    ->key('salary-report')
                                    ->columnSpanFull()
                                    ->viewData(fn (Get $get, Doctor $record): array => [
                                        'report' => self::salaryReport($record, $get),
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
                            );
                            $record->clearCompensationSummaryCache();
                            Notification::make()->success()->title('ხელფასი დაფიქსირდა.')->send();
                        }),
                    Action::make('salaryHistory')->label('ხელფასების ისტორია')->color('gray')
                        ->url(fn (Doctor $record): string => DoctorCompensation::getUrl(['doctor' => $record->getKey()]).'#history'),
                ])
                ->schema([
                    TextEntry::make('unsettled_work')->label('დაუხურავი სამუშაო')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getCompensationSummary()['totals'], 'work_total'))->listWithLineBreaks(),
                    TextEntry::make('unsettled_expenses')->label('პირდაპირი ხარჯები')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getCompensationSummary()['totals'], 'expense_total'))->listWithLineBreaks(),
                    TextEntry::make('unsettled_base')->label('სავარაუდო საბაზო თანხა')
                        ->state(fn (Doctor $record): array => Currency::formatBreakdown($record->getCompensationSummary()['totals'], 'base_total'))->listWithLineBreaks(),
                    TextEntry::make('last_salary_date')->label('ბოლო დაფიქსირების თარიღი')
                        ->state(fn (Doctor $record): string => $record->getCompensationSummary()['last_settled_at']?->format('d.m.Y H:i') ?? '—'),
                    TextEntry::make('last_salary_amount')->label('ბოლო ხელფასი')
                        ->state(fn (Doctor $record): string => $record->getCompensationSummary()['last_salary']),
                    TextEntry::make('last_salary_patient')->label('ბოლო ჩათვლილი პაციენტი')
                        ->state(fn (Doctor $record): string => $record->getCompensationSummary()['last_patient']),
                    TextEntry::make('last_salary_visit')->label('ბოლო ჩათვლილი Visit')
                        ->state(fn (Doctor $record): string => filled($record->getCompensationSummary()['last_visit_id'])
                            ? 'Visit #'.$record->getCompensationSummary()['last_visit_id']
                            : '—'),
                ])->columns(7),
        ]);
    }

    /** @return array<string, mixed> */
    private static function salaryReport(Doctor $doctor, Get $get): array
    {
        $from = $get('from');
        $until = $get('until');
        $percentage = $get('percentage');

        if (blank($from) || blank($until) || (! is_numeric($percentage)) || $until < $from) {
            return ['totals' => [], 'details' => [], 'percentage' => (float) ($percentage ?: 0)];
        }

        return app(DoctorCompensationCalculator::class)->calculate(
            $doctor->getKey(),
            $from,
            $until,
            (float) $percentage,
            filled($get('cutoff_visit_id')) ? (int) $get('cutoff_visit_id') : null,
        );
    }
}
