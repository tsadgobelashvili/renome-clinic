<?php

namespace App\Filament\Resources\Doctors\RelationManagers;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\SalarySettlement;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    private bool $salaryBoundaryResolved = false;

    private ?int $salaryBoundaryVisitId = null;

    protected static ?string $title = 'ვიზიტების ისტორია';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['patient', 'payments', 'treatmentCaseItems.treatmentCase']))
            ->columns([
                TextColumn::make('visit_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('patient.full_name')->label('პაციენტი')->searchable(['first_name', 'last_name']),
                TextColumn::make('treatment_cases')
                    ->label('მომსახურება')
                    ->state(function (Visit $record): string {
                        $names = $record->treatmentCaseItems
                            ->map(fn (VisitTreatmentCase $item): string => self::manipulationLabel($item))
                            ->filter()
                            ->values();

                        if ($names->isEmpty()) {
                            return '—';
                        }

                        return $names->count() > 2
                            ? $names->take(2)->implode(', ').' +'.($names->count() - 2)
                            : $names->implode(', ');
                    })
                    ->limit(55)
                    ->tooltip(fn (Visit $record): ?string => $record->treatmentCaseItems->count() > 2
                        ? $record->treatmentCaseItems->map(fn (VisitTreatmentCase $item): string => self::manipulationLabel($item))->filter()->implode(', ')
                        : null),
                TextColumn::make('total_price')->label('თანხა')->formatStateUsing(
                    fn ($state, Visit $record): string => self::money($state, $record->currency),
                )->alignEnd()->extraCellAttributes(['class' => 'whitespace-nowrap']),
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
                    })
                    ->alignCenter(),
                IconColumn::make('salary_settlement_boundary')
                    ->label('')
                    ->state(fn (Visit $record): bool => $record->getKey() === $this->salaryBoundaryVisitId())
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-m-check-circle' : null)
                    ->color('gray')
                    ->tooltip(fn (bool $state): ?string => $state ? 'დათვლილია აქამდე' : null)
                    ->alignCenter()
                    ->width('36px'),
            ])
            ->headerActions([
                Action::make('createVisit')
                    ->label('ახალი ვიზიტი')
                    ->visible(fn (): bool => (bool) $this->getOwnerRecord()->is_active)
                    ->url(fn (): string => VisitResource::getUrl('create', [
                        'doctor_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordUrl(fn (Visit $record): string => VisitResource::getUrl('edit', ['record' => $record]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->defaultSort('visit_date', 'desc');
    }

    private function salaryBoundaryVisitId(): ?int
    {
        if ($this->salaryBoundaryResolved) {
            return $this->salaryBoundaryVisitId;
        }

        $this->salaryBoundaryResolved = true;
        $settlement = SalarySettlement::query()
            ->where('doctor_id', $this->getOwnerRecord()->getKey())
            ->where('status', 'confirmed')
            ->whereHas('items')
            ->latest('settled_at')
            ->latest('id')
            ->first();

        if (! $settlement) {
            return null;
        }

        return $this->salaryBoundaryVisitId = $settlement->items()
            ->join('visits', 'visits.id', '=', 'salary_settlement_items.visit_id')
            ->orderByDesc('visits.visit_date')
            ->orderByDesc('visits.id')
            ->orderByDesc('salary_settlement_items.id')
            ->value('salary_settlement_items.visit_id');
    }

    private static function money(mixed $amount, string $currency): string
    {
        return $amount === null ? '—' : Currency::format($amount, $currency);
    }

    private static function manipulationLabel(VisitTreatmentCase $item): string
    {
        return $item->display_name.' ×'.number_format((int) $item->quantity);
    }
}
