<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Filament\Actions\DoctorSalaryAction;
use App\Models\Doctor;
use App\Models\TreatmentCase;
use App\Support\Currency;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class DoctorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ექიმის ინფორმაცია')
                ->compact()
                ->schema([
                    TextEntry::make('full_name')->label('სახელი და გვარი'),
                    TextEntry::make('phone')->label('ტელეფონი')->placeholder('—'),
                    TextEntry::make('specialties')->label('სპეციალობები')->placeholder('—')
                        ->state(fn (Doctor $record): string => collect($record->specialties ?? [])
                            ->map(fn (string $key): string => TreatmentCase::CATEGORIES[$key] ?? $key)
                            ->implode(', ') ?: ($record->specialty ?? '')),
                    IconEntry::make('is_active')->label('აქტიური')->boolean(),
                ])->columns(['default' => 1, 'md' => 2]),

            Section::make('ვიზიტებისა და ფინანსების შეჯამება')
                ->compact()
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
                ])->columns(['default' => 1, 'md' => 2, 'xl' => 3]),

            Section::make('ანაზღაურება')
                ->key('compensation')
                ->compact()
                ->headerActions([
                    DoctorSalaryAction::make(),
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
}
