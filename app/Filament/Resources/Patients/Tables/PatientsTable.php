<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Patient;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'visits' => fn ($query) => $query
                    ->with('payments')
                    ->orderByDesc('visit_date')
                    ->orderByDesc('id'),
            ]))
            ->columns([
                TextColumn::make('full_name')
                    ->label('პაციენტი')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->searchForClinic($search))
                    ->sortable(['last_name', 'first_name'])
                    ->weight('medium'),

                TextColumn::make('phone')
                    ->label('მობილური')
                    ->placeholder('—'),

                TextColumn::make('personal_id')
                    ->label('პირადი ნომერი')
                    ->placeholder('—'),

                TextColumn::make('last_visit')
                    ->label('ბოლო ვიზიტი')
                    ->state(fn (Patient $record): ?string => $record->visits->first()?->visit_date?->format('d.m.y'))
                    ->placeholder('—'),

                TextColumn::make('remaining_amount')
                    ->label('გადასახდელი')
                    ->state(fn (Patient $record): array => Currency::formatBreakdown(
                        $record->getFinancialSummariesByCurrency(),
                        'remaining_amount',
                    ))
                    ->listWithLineBreaks()
                    ->weight(FontWeight::SemiBold)
                    ->color(fn (Patient $record): string => collect($record->getFinancialSummariesByCurrency())
                        ->contains(fn (array $summary): bool => $summary['remaining_amount'] > 0)
                            ? 'danger'
                            : 'success'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('ნახვა'),
                EditAction::make()->iconButton()->tooltip('რედაქტირება'),
                Action::make('createVisit')
                    ->label('ახალი ვიზიტი')
                    ->iconButton()
                    ->tooltip('ახალი ვიზიტი')
                    ->url(fn (Patient $record): string => VisitResource::getUrl('create', [
                        'patient_id' => $record->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Patient $record): string => PatientResource::getUrl('view', [
                'record' => $record,
            ]))
            ->defaultSort('created_at', 'desc');
    }
}
