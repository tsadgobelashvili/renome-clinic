<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    protected static ?string $title = 'ისტორია';

    public function table(Table $table): Table
    {
        $doctorOptions = Doctor::query()
            ->whereHas('visits', fn (Builder $query): Builder => $query
                ->where('patient_id', $this->getOwnerRecord()->getKey()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])
            ->all();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['patient.patientGroup', 'doctor', 'treatmentCaseItems.treatmentCase', 'payments']))
            ->columns([
                TextColumn::make('visit_date')
                    ->label('თარიღი')
                    ->date('d.m.y')
                    ->description(fn (Visit $record): string => 'Visit #'.$record->getKey())
                    ->sortable(),

                TextColumn::make('doctor.full_name')
                    ->label(view('filament.resources.patients.visit-history-doctor-header', [
                        'doctors' => $doctorOptions,
                    ]))
                    ->placeholder('—'),

                TextColumn::make('teeth')
                    ->label('კბილი')
                    ->state(fn (Visit $record): string => $record->treatmentCaseItems
                        ->pluck('teeth')->filter()->unique()->values()->implode(', '))
                    ->placeholder('—'),

                TextColumn::make('services_summary')
                    ->label('მომსახურება / მანიპულაცია')
                    ->state(function (Visit $record): string {
                        $items = $record->treatmentCaseItems
                            ->map(fn ($item): string => $item->display_name.' ×'.(int) $item->quantity)
                            ->filter()->values();

                        if ($items->isEmpty()) {
                            return '—';
                        }

                        return $items->count() > 2
                            ? $items->take(2)->implode(', ').' +'.($items->count() - 2)
                            : $items->implode(', ');
                    })
                    ->limit(60)
                    ->tooltip(fn (Visit $record): ?string => $record->treatmentCaseItems->count() > 2
                        ? $record->treatmentCaseItems
                            ->map(fn ($item): string => $item->display_name.' ×'.(int) $item->quantity)
                            ->implode(', ')
                        : null)
                    ->placeholder('—'),

                TextColumn::make('net_amount')
                    ->label('თანხა')
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency))
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),

                TextColumn::make('payment_status')
                    ->label('სტატუსი')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid' => 'გადახდილია',
                        'free' => 'უფასოა',
                        'unpriced' => 'ფასი არაა',
                        default => 'დარჩენილია',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid', 'free' => 'success',
                        'unpriced' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('doctor_id')
                    ->label('ექიმი')
                    ->placeholder('ყველა')
                    ->native(false)
                    ->options($doctorOptions),
            ], FiltersLayout::Hidden)
            ->deferFilters(false)
            ->searchable(false)
            ->recordUrl(fn (Visit $record): string => VisitResource::getUrl('edit', [
                'record' => $record,
            ]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('ვიზიტები ჯერ არ არის.')
            ->defaultSort('visit_date', 'desc');
    }
}
