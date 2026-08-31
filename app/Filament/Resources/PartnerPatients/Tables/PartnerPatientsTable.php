<?php

namespace App\Filament\Resources\PartnerPatients\Tables;

use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerPatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'doctors' => fn ($query) => $query
                    ->orderByDesc('patient_doctor.is_primary')
                    ->orderBy('first_name')
                    ->orderBy('last_name'),
            ]))
            ->columns([
                TextColumn::make('full_name')
                    ->label('პაციენტი')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchForClinic($search))
                    ->sortable(['last_name', 'first_name'])
                    ->weight('medium'),
                TextColumn::make('birth_date')
                    ->label('დაბადების თარიღი')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                TextColumn::make('assigned_doctors')
                    ->label('ექიმი')
                    ->state(fn (Patient $record): array => $record->doctors
                        ->unique(fn (Doctor $doctor): int => $doctor->getKey())
                        ->map(fn (Doctor $doctor): string => $doctor->full_name)
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label('ტელეფონი')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('createVisit')
                    ->label('ახალი ვიზიტი')
                    ->iconButton()
                    ->tooltip('ახალი ვიზიტი')
                    ->url(fn (Patient $record): string => VisitResource::getUrl('create', [
                        'patient_id' => $record->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Patient $record): string => PartnerPatientResource::getUrl('view', [
                'record' => $record,
            ]))
            ->defaultSort('created_at', 'desc');
    }
}
