<?php

namespace App\Filament\Resources\Visits\Tables;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.resources.visits.table-toolbar', [
                'createUrl' => VisitResource::getUrl('create'),
                'doctors' => Doctor::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'last_name']),
                'datePresets' => [
                    '7' => ['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString()],
                    '14' => ['from' => today()->subDays(13)->toDateString(), 'until' => today()->toDateString()],
                    'month' => ['from' => today()->subMonth()->toDateString(), 'until' => today()->toDateString()],
                    '3months' => ['from' => today()->subMonths(3)->toDateString(), 'until' => today()->toDateString()],
                    '6months' => ['from' => today()->subMonths(6)->toDateString(), 'until' => today()->toDateString()],
                    'year' => ['from' => today()->subYear()->toDateString(), 'until' => today()->toDateString()],
                    'all' => ['from' => null, 'until' => null],
                ],
            ]))
            ->columns([
                TextColumn::make('visit_date')
                    ->label('თარიღი')
                    ->date('d.m.y')
                    ->width('90px')
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('პაციენტი')
                    ->width('180px')
                    ->searchable(['first_name', 'last_name', 'phone', 'personal_id']),

                TextColumn::make('doctor.full_name')
                    ->label('ექიმი')
                    ->width('170px')
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('treatment_cases_summary')
                    ->label('შესრულებული სამუშაო')
                    ->state(function (Visit $record): string {
                        $items = $record->treatmentCaseItems;
                        $first = $items->first()?->treatmentCase?->name;

                        if (blank($first)) {
                            return '—';
                        }

                        $remainingCount = $items->count() - 1;

                        return $first.($remainingCount > 0 ? " +{$remainingCount}" : '');
                    })
                    ->limit(38)
                    ->tooltip(fn (Visit $record): ?string => $record->treatmentCaseItems->count() > 1
                        ? $record->treatmentCaseItems
                            ->map(fn ($item): string => $item->treatmentCase?->name ?? '')
                            ->filter()->join(', ')
                        : null),

                TextColumn::make('total_price')
                    ->label('სრული')
                    ->width('110px')
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency)),

                TextColumn::make('paid_amount')
                    ->label('გადახდილი')
                    ->width('110px')
                    ->formatStateUsing(fn ($state, Visit $record): string => Currency::format($state, $record->currency))
                    ->color('success')
                    ->weight(FontWeight::Medium),

                TextColumn::make('remaining_amount')
                    ->label('გადასახდელი')
                    ->width('120px')
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency))
                    ->color(fn ($state): string => ((float) ($state ?? 0)) > 0 ? 'danger' : 'success')
                    ->weight(FontWeight::SemiBold),
            ])
            ->filters([
                SelectFilter::make('doctor_id')
                    ->label('ექიმი')
                    ->relationship('doctor', 'first_name')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => $record->full_name
                    )
                    ->searchable(['first_name', 'last_name'])
                    ->preload(),

                Filter::make('visit_date')
                    ->label('ვიზიტის პერიოდი')
                    ->schema([
                        DatePicker::make('from')
                            ->label('თარიღიდან')
                            ->default(fn (): string => today()->subDays(6)->toDateString())
                            ->displayFormat('d.m.Y'),

                        DatePicker::make('until')
                            ->label('თარიღამდე')
                            ->default(fn (): string => today()->toDateString())
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('visit_date', '>=', $date),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('visit_date', '<=', $date),
                        )),
            ], FiltersLayout::Hidden)
            ->deferFilters(false)
            ->recordActions([
                Action::make('view')
                    ->label('ნახვა')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('primary')
                    ->tooltip('ნახვა')
                    ->url(fn (Visit $record): string => VisitResource::getUrl('edit', ['record' => $record])),
                EditAction::make()
                    ->label('რედაქტირება')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->color('primary')
                    ->tooltip('რედაქტირება'),
            ])
            ->recordActionsAlignment('end')
            ->recordActionsColumnLabel('მოქმედებები')
            ->searchable(false)
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->extremePaginationLinks()
            ->defaultSort('visit_date', 'desc');
    }
}
