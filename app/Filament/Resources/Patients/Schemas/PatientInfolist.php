<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use App\Support\Currency;
use Filament\Infolists\Components\TextEntry;
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
                                $first = $items->first()?->treatmentCase?->name;
                                $work = $first
                                    ? $first.($items->count() > 1 ? ' +'.($items->count() - 1) : '')
                                    : 'შესრულებული სამუშაო არ არის';

                                return [
                                    $visit->visit_date->format('d.m.y'),
                                    $work,
                                    'ექიმი: '.$visit->doctor->full_name,
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
