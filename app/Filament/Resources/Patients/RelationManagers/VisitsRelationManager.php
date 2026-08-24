<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    protected static ?string $title = 'ვიზიტების ისტორია';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['doctor', 'treatmentCaseItems.treatmentCase', 'payments']))
            ->columns([
                TextColumn::make('visit_date')
                    ->label('თარიღი')
                    ->date('d.m.y')
                    ->sortable(),

                TextColumn::make('doctor.full_name')
                    ->label('ექიმი')
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('treatment_cases')
                    ->label('შესრულებული სამუშაო')
                    ->state(function (Visit $record): string {
                        $items = $record->treatmentCaseItems;
                        $first = $items->first()?->treatmentCase?->name;

                        return $first
                            ? $first.($items->count() > 1 ? ' +'.($items->count() - 1) : '')
                            : '—';
                    })
                    ->limit(38),

                TextColumn::make('total_price')
                    ->label('სრული თანხა')
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency)),

                TextColumn::make('paid_amount')
                    ->label('გადახდილი')
                    ->formatStateUsing(fn ($state, Visit $record): string => Currency::format($state, $record->currency)),

                TextColumn::make('remaining_amount')
                    ->label('გადასახდელი')
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency))
                    ->badge()
                    ->color(fn ($state): string => ($state !== null) && ((float) $state <= 0)
                        ? 'success'
                        : 'warning'),
            ])
            ->headerActions([
                Action::make('createVisit')
                    ->label('ახალი ვიზიტი')
                    ->url(fn (): string => VisitResource::getUrl('create', [
                        'patient_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Visit $record): string => VisitResource::getUrl('edit', [
                'record' => $record,
            ]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('ვიზიტები ჯერ არ არის.')
            ->defaultSort('visit_date', 'desc');
    }
}
