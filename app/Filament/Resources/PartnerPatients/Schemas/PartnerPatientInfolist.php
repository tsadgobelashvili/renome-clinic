<?php

namespace App\Filament\Resources\PartnerPatients\Schemas;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerPatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('პაციენტის ინფორმაცია')
                ->schema([
                    TextEntry::make('full_name')->label('სახელი და გვარი'),
                    TextEntry::make('birth_date')
                        ->label('დაბადების თარიღი')
                        ->date('d.m.Y')
                        ->placeholder('—'),
                    TextEntry::make('phone')->label('ტელეფონი')->placeholder('—'),
                    TextEntry::make('notes')
                        ->label('შენიშვნა')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->compact(),

            Section::make('ექიმები')
                ->schema([
                    TextEntry::make('partner_doctors')
                        ->hiddenLabel()
                        ->state(fn (Patient $record): array => $record->doctors()
                            ->orderByDesc('patient_doctor.is_primary')
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->groupBy(fn (Doctor $doctor): int => $doctor->getKey())
                            ->map(function ($relations): string {
                                /** @var Doctor $doctor */
                                $doctor = $relations->first();
                                $roles = $relations->pluck('pivot.role')->filter()->unique()->implode(', ');

                                return (filled($roles) ? $roles.' — ' : '').$doctor->full_name;
                            })
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('ექიმი ჯერ არ არის მიბმული.'),
                ])
                ->compact(),

            Section::make('ვიზიტები და მკურნალობის ისტორია')
                ->schema([
                    TextEntry::make('partner_visit_history')
                        ->hiddenLabel()
                        ->state(fn (Patient $record): array => $record->visits()
                            ->with(['doctor', 'treatmentCaseItems.treatmentCase'])
                            ->orderByDesc('visit_date')
                            ->orderByDesc('id')
                            ->limit(50)
                            ->get()
                            ->map(fn (Visit $visit): string => implode(' — ', [
                                $visit->visit_date->format('d.m.Y'),
                                $visit->doctor?->full_name ?? 'ექიმი —',
                                $visit->treatmentCaseItems
                                    ->map(fn ($item): string => $item->display_name.' ×'.(int) $item->quantity)
                                    ->filter()
                                    ->join(', ') ?: 'მანიპულაცია არ არის',
                            ]))
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('ვიზიტების ისტორია ჯერ არ არის.'),
                ])
                ->compact(),

            Section::make('გადახდების შეჯამება')
                ->schema([
                    TextEntry::make('partner_payment_totals')
                        ->label('სულ გადახდილი')
                        ->state(function (Patient $record): array {
                            $totals = $record->getPartnerPaymentTotals();

                            return collect(Currency::OPTIONS)
                                ->map(fn (string $symbol, string $currency): string => $currency.': '.Currency::format(
                                    $totals[$currency] ?? 0,
                                    $currency,
                                ))
                                ->values()
                                ->all();
                        })
                        ->listWithLineBreaks(),
                ])
                ->compact(),
        ]);
    }
}
