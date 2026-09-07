<?php

namespace App\Filament\Resources\PartnerPatients\Schemas;

use App\Models\Doctor;
use App\Models\Patient;
use App\Support\PartnerPatientHistory;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerPatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
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
                        ->visible(fn (Patient $record): bool => filled($record->notes))
                        ->columnSpanFull(),
                    TextEntry::make('partner_doctors')
                        ->label('ექიმები')
                        ->visible(fn (Patient $record): bool => $record->doctors()->exists())
                        ->columnSpanFull()
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
                        ->placeholder('ექიმი ჯერ არ არის მიბმული.'),
                ])
                ->columns(3)
                ->compact(),

            Section::make(fn (): string => app()->getLocale() === 'ka' ? 'მკურნალობის ისტორია' : 'Treatment History')
                ->schema([
                    TextEntry::make('partner_visit_history')
                        ->hiddenLabel()
                        ->state(fn (Patient $record): array => PartnerPatientHistory::rows($record))
                        ->view('filament.resources.partner-patients.history'),
                ])
                ->compact(),

        ]);
    }
}
