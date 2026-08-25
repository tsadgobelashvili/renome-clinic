<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Doctor;
use App\Models\Patient;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('პაციენტის ინფორმაცია')
                    ->schema([
                        TextEntry::make('full_name')
                            ->label('სახელი და გვარი'),

                        TextEntry::make('phone')
                            ->label('ტელეფონი')
                            ->placeholder('—'),

                        TextEntry::make('personal_id')
                            ->label('პირადი ნომერი')
                            ->placeholder('—'),

                        TextEntry::make('birth_date')
                            ->label('დაბადების თარიღი')
                            ->date('d.m.Y')
                            ->placeholder('—'),

                        TextEntry::make('notes')
                            ->label('შენიშვნა')
                            ->visible(fn (Patient $record): bool => filled($record->notes))
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->compact(),

                Section::make('ექიმები')
                    ->schema([
                        TextEntry::make('assigned_doctors')
                            ->hiddenLabel()
                            ->state(fn (Patient $record): array => $record->doctors()
                                ->orderByDesc('patient_doctor.is_primary')
                                ->orderBy('first_name')
                                ->orderBy('last_name')
                                ->get()
                                ->map(fn (Doctor $doctor): string => (filled($doctor->specialty)
                                    ? $doctor->specialty.' — '
                                    : '').$doctor->full_name)
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('ექიმი ჯერ არ არის მიბმული.'),
                        Actions::make([
                            Action::make('attachDoctor')
                                ->label('+ ექიმის დამატება')
                                ->link()
                                ->modalHeading('ექიმის დამატება')
                                ->modalSubmitActionLabel('დამატება')
                                ->schema([
                                    Select::make('doctor_id')
                                        ->label('ექიმი')
                                        ->options(fn (Patient $record): array => Doctor::query()
                                            ->where('is_active', true)
                                            ->whereNotIn('id', $record->doctors()->select('doctors.id'))
                                            ->orderBy('first_name')
                                            ->orderBy('last_name')
                                            ->get()
                                            ->mapWithKeys(fn (Doctor $doctor): array => [
                                                $doctor->getKey() => $doctor->full_name
                                                    .(filled($doctor->specialty) ? ' — '.$doctor->specialty : ''),
                                            ])->all())
                                        ->searchable()
                                        ->native(false)
                                        ->required(),
                                ])
                                ->action(function (array $data, Patient $record): void {
                                    $record->doctors()->syncWithoutDetaching([
                                        $data['doctor_id'] => ['is_primary' => false],
                                    ]);
                                    $record->unsetRelation('doctors');

                                    Notification::make()
                                        ->success()
                                        ->title('ექიმი პაციენტს დაემატა.')
                                        ->send();
                                }),
                        ]),
                    ])
                    ->compact(),

                Section::make('ფინანსური შეჯამება')
                    ->schema([
                        TextEntry::make('visits_total_price')
                            ->label('სულ დარიცხული')
                            ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'net_amount'))
                            ->listWithLineBreaks(),
                        TextEntry::make('visits_paid_amount')
                            ->label('სულ გადახდილი')
                            ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'paid_amount'))
                            ->listWithLineBreaks(),
                        TextEntry::make('visits_remaining_amount')
                            ->label('სულ გადასახდელი')
                            ->state(fn (Patient $record): array => Currency::formatBreakdown($record->getFinancialSummariesByCurrency(), 'remaining_amount'))
                            ->listWithLineBreaks(),
                    ])
                    ->columns(3)
                    ->compact(),

                Section::make('ბოლო აქტივობა')
                    ->schema([
                        TextEntry::make('latest_visit_activity')
                            ->label('ბოლო ვიზიტი')
                            ->state(function (Patient $record): array {
                                $visit = $record->getLatestVisitRecord();

                                if (! $visit) {
                                    return ['ჯერ არ არის'];
                                }

                                $items = $visit->treatmentCaseItems;
                                $first = $items->first()?->display_name;
                                $work = $first
                                    ? $first.($items->count() > 1 ? ' +'.($items->count() - 1) : '')
                                    : 'შესრულებული სამუშაო არ არის';

                                return [
                                    $visit->visit_date->format('d.m.y'),
                                    $work,
                                    'ექიმი: '.($visit->doctor?->full_name ?? '—'),
                                    $visit->total_price === null ? '—' : Currency::format($visit->total_price, $visit->currency),
                                ];
                            })
                            ->listWithLineBreaks(),
                        TextEntry::make('latest_payment_activity')
                            ->label('ბოლო გადახდა')
                            ->state(function (Patient $record): array {
                                $payment = $record->getLatestPaymentRecord();

                                if (! $payment) {
                                    return ['ჯერ არ არის'];
                                }

                                return [
                                    $payment->payment_date->format('d.m.y'),
                                    Currency::format($payment->amount, $payment->currency),
                                    $payment->method_display,
                                ];
                            })
                            ->listWithLineBreaks(),
                    ])
                    ->columns(2)
                    ->compact(),
            ]);
    }
}
