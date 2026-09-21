<?php

namespace App\Filament\Resources\Visits\Tables;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Models\Visit;
use App\Support\Currency;
use App\Support\PaymentPresentation;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsTable
{
    public static function configure(Table $table, ?string $createUrl = null, bool $todayByDefault = false): Table
    {
        $showActualPaymentCurrency = $createUrl !== null;
        $datePresets = [
            '7' => ['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString()],
            '14' => ['from' => today()->subDays(13)->toDateString(), 'until' => today()->toDateString()],
            'month' => ['from' => today()->subMonth()->toDateString(), 'until' => today()->toDateString()],
            '3months' => ['from' => today()->subMonths(3)->toDateString(), 'until' => today()->toDateString()],
            '6months' => ['from' => today()->subMonths(6)->toDateString(), 'until' => today()->toDateString()],
            'year' => ['from' => today()->subYear()->toDateString(), 'until' => today()->toDateString()],
            'all' => ['from' => null, 'until' => null],
        ];

        if ($todayByDefault) {
            $datePresets = [
                'today' => ['from' => today()->toDateString(), 'until' => today()->toDateString()],
                ...$datePresets,
            ];
        }

        return $table
            ->splitSearchTerms(fn ($livewire): bool => ! preg_match('/^\s*[0-9][0-9\s-]*\s*$/u', $livewire->getTableSearch() ?? ''))
            ->header(fn () => view('filament.resources.visits.table-toolbar', [
                'createUrl' => $createUrl ?? VisitResource::getUrl('create'),
                'doctors' => Doctor::query()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'last_name']),
                'datePresets' => $datePresets,
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
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('patient', fn (Builder $patients): Builder => $patients->searchForClinic($search))),

                TextColumn::make('doctor.full_name')
                    ->label('ექიმი')
                    ->width('170px')
                    ->placeholder('—')
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('treatment_cases_summary')
                    ->label('შესრულებული სამუშაო')
                    ->state(function (Visit $record): string {
                        $consultationWithCt = self::hasConsultationWithCt($record);
                        $labels = $record->treatmentCaseItems
                            ->sortBy(fn ($item): int => $consultationWithCt && self::isCt($item) ? 0 : 1)
                            ->map(function ($item) use ($consultationWithCt): string {
                                $label = self::treatmentItemLabel($item, true);

                                return $consultationWithCt && self::isCt($item)
                                    ? '<span class="renome-treatment-pair">'.$label.'<span class="renome-treatment-service renome-treatment-consultation">კონსულტაცია</span></span>'
                                    : $label;
                            })
                            ->filter()
                            ->values();

                        if ($labels->isEmpty()) {
                            return '—';
                        }

                        $remainingCount = $labels->count() - 2;

                        return $labels->take(2)->join(', ').($remainingCount > 0 ? " +{$remainingCount}" : '');
                    })
                    ->html()
                    ->limit(fn (Visit $record): ?int => self::hasConsultationWithCt($record) ? null : 38)
                    ->tooltip(fn (Visit $record): ?string => $record->treatmentCaseItems->count() > 1
                        ? $record->treatmentCaseItems
                            ->map(fn ($item): string => self::treatmentItemLabel($item))
                            ->filter()->join(', ')
                        : null),

                TextColumn::make('total_price')
                    ->label('სრული')
                    ->width('110px')
                    ->alignEnd()
                    ->extraHeaderAttributes(['class' => 'renome-financial-header'])
                    ->extraCellAttributes(['class' => 'renome-financial-cell whitespace-nowrap'])
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency)),

                TextColumn::make('paid_amount')
                    ->label('გადახდილი')
                    ->width('110px')
                    ->alignEnd()
                    ->extraHeaderAttributes(['class' => 'renome-financial-header'])
                    ->extraCellAttributes(['class' => 'renome-financial-cell whitespace-nowrap'])
                    ->formatStateUsing(fn ($state, Visit $record) => $showActualPaymentCurrency
                        ? PaymentPresentation::methodAmountsHtml($record->payments, $record->currency)
                        : Currency::format($state, $record->currency))
                    ->html($showActualPaymentCurrency)
                    ->color('success')
                    ->weight(FontWeight::Medium),

                TextColumn::make('remaining_amount')
                    ->label('გადასახდელი')
                    ->width('120px')
                    ->alignEnd()
                    ->extraHeaderAttributes(['class' => 'renome-financial-header'])
                    ->extraCellAttributes(['class' => 'renome-financial-cell whitespace-nowrap'])
                    ->formatStateUsing(fn ($state, Visit $record): string => $state === null
                        ? '—'
                        : Currency::format($state, $record->currency))
                    ->color(fn ($state): string => ((float) ($state ?? 0)) > 0 ? 'danger' : 'gray')
                    ->weight(FontWeight::SemiBold),
            ])
            ->filters([
                SelectFilter::make('patient_group_id')
                    ->label('პაციენტის ჯგუფი')
                    ->placeholder('ყველა ჯგუფი')
                    ->options(fn (): array => PatientGroup::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'patient',
                            fn (Builder $query): Builder => $query->where('patient_group_id', $data['value']),
                        ),
                    )),

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
                            ->default($todayByDefault
                                ? fn (): string => today()->toDateString()
                                : fn (): string => today()->subDays(6)->toDateString())
                            ->displayFormat('d.m.Y'),

                        DatePicker::make('until')
                            ->label('თარიღამდე')
                            ->default(fn (): string => today()->toDateString())
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, $date): Builder => $query->where(
                                'visit_date',
                                '>=',
                                CarbonImmutable::parse($date)->startOfDay(),
                            ),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $query, $date): Builder => $query->where(
                                'visit_date',
                                '<',
                                CarbonImmutable::parse($date)->addDay()->startOfDay(),
                            ),
                        )),
            ], FiltersLayout::Hidden)
            ->deferFilters(false)
            ->searchable(false)
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->extremePaginationLinks()
            ->defaultSort('visit_date', 'desc');
    }

    private static function isCt(mixed $item): bool
    {
        return mb_strtoupper(trim((string) $item->display_name)) === '3D CT';
    }

    private static function hasConsultationWithCt(Visit $visit): bool
    {
        return ($visit->visit_type === 'consultation'
            || $visit->treatmentCaseItems->contains(fn ($item): bool => $item->treatmentCase?->category === 'consultation'))
            && $visit->treatmentCaseItems->contains(fn ($item): bool => self::isCt($item));
    }

    private static function treatmentItemLabel(mixed $item, bool $quantityChip = false): string
    {
        $name = trim((string) $item->display_name);

        if ($name === '') {
            return '';
        }

        $quantity = max(1, (int) ($item->quantity ?? 1));

        return $quantityChip
            ? '<span class="renome-treatment-service">'.e($name).' x'.$quantity.'</span>'
            : $name.' x'.$quantity;
    }
}
