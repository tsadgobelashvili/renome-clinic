<?php

namespace App\Filament\Resources\DirectExpenses\Tables;

use App\Models\Doctor;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DirectExpensesTable
{
    public const ELIGIBLE_CATEGORIES = ['surgery', 'orthopedics'];

    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.resources.direct-expenses.table-toolbar', [
                'doctors' => Doctor::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            ]))
            ->columns([
                TextColumn::make('visit_date')->label('თარიღი')->date('d.m.Y')->sortable()
                    ->width('10%')->extraCellAttributes(['class' => '!px-3 !py-2 align-top text-xs whitespace-nowrap']),
                TextColumn::make('patient.first_name')->label('პაციენტი')
                    ->width('17%')->extraCellAttributes(['class' => '!px-3 !py-2 align-top text-xs'])
                    ->formatStateUsing(fn (Visit $record): string => $record->patient->full_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'patient',
                        fn (Builder $patient): Builder => $patient
                            ->whereRaw('LOWER(first_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%'.mb_strtolower($search).'%']),
                    )),
                TextColumn::make('doctor.first_name')->label('ექიმი')
                    ->width('17%')->extraCellAttributes(['class' => '!px-3 !py-2 align-top text-xs'])
                    ->formatStateUsing(fn (Visit $record): string => $record->doctor?->full_name ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'doctor',
                        fn (Builder $doctor): Builder => $doctor
                            ->whereRaw('LOWER(first_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", ['%'.mb_strtolower($search).'%']),
                    )),
                ViewColumn::make('manipulations')->label('მანიპულაციები')
                    ->width('25%')->extraCellAttributes(['class' => '!px-3 !py-2 align-top'])
                    ->view('filament.resources.direct-expenses.manipulation-summary')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'treatmentCaseItems',
                        fn (Builder $item): Builder => $item->whereHas(
                            'treatmentCase',
                            fn (Builder $service): Builder => $service
                                ->whereIn('category', self::ELIGIBLE_CATEGORIES)
                                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']),
                        ),
                    )),
                ViewColumn::make('direct_expense_total')->label('პირდაპირი ხარჯი')
                    ->width('31%')->extraCellAttributes(['class' => '!px-3 !py-2 align-top'])
                    ->view('filament.resources.direct-expenses.expense-editor'),
            ])
            ->filters([
                TernaryFilter::make('expense_status')->label('ხარჯი შევსებულია')
                    ->placeholder('ყველა')->trueLabel('შევსებულია')->falseLabel('არ არის შევსებული')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('treatmentCaseItems', fn (Builder $item): Builder => $item
                            ->whereHas('treatmentCase', fn (Builder $service): Builder => $service->whereIn('category', self::ELIGIBLE_CATEGORIES))
                            ->whereHas('directExpenses')),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('treatmentCaseItems', fn (Builder $item): Builder => $item
                            ->whereHas('treatmentCase', fn (Builder $service): Builder => $service->whereIn('category', self::ELIGIBLE_CATEGORIES))
                            ->whereHas('directExpenses')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('visit_date')->label('თარიღის პერიოდი')->schema([
                    DatePicker::make('from')->label('თარიღიდან')
                        ->default(fn (): string => today()->subDays(13)->toDateString())->displayFormat('d.m.Y'),
                    DatePicker::make('until')->label('თარიღამდე')
                        ->default(fn (): string => today()->toDateString())->displayFormat('d.m.Y'),
                ])->columns(2)->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('visit_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('visit_date', '<=', $date))),
                SelectFilter::make('doctor_id')->label('ექიმი')->placeholder('ყველა ექიმი')
                    ->options(fn (): array => Doctor::query()->orderBy('first_name')->orderBy('last_name')->get()
                        ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->getKey() => $doctor->full_name])->all())
                    ->searchable(),
            ], FiltersLayout::Hidden)
            ->deferFilters(false)
            ->searchable(false)
            ->recordActions([])
            ->defaultSort('visit_date', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function visitWorkTotal(Visit $visit): float
    {
        return round(self::eligibleItems($visit)->sum(
            fn (VisitTreatmentCase $item): float => $item->manipulation_total,
        ), 2);
    }

    public static function visitExpenseTotal(Visit $visit): float
    {
        return round(self::eligibleItems($visit)->sum(
            fn (VisitTreatmentCase $item): float => (float) $item->directExpenses
                ->where('currency', $visit->currency ?: Currency::DEFAULT)->sum('amount'),
        ), 2);
    }

    /** @return Collection<int, VisitTreatmentCase> */
    public static function eligibleItems(Visit $visit): Collection
    {
        $items = $visit->relationLoaded('treatmentCaseItems')
            ? $visit->treatmentCaseItems
            : $visit->treatmentCaseItems()->with(['treatmentCase', 'directExpenses'])->get();

        return $items->filter(fn (VisitTreatmentCase $item): bool => in_array(
            $item->treatmentCase?->category,
            self::ELIGIBLE_CATEGORIES,
            true,
        ))->values();
    }
}
