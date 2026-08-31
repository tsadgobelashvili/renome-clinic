<?php

namespace App\Filament\Resources\Doctors\RelationManagers;

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
                ->with(['patient', 'payments', 'treatmentCaseItems.treatmentCase']))
            ->columns([
                TextColumn::make('visit_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('patient.full_name')->label('პაციენტი')->searchable(['first_name', 'last_name']),
                TextColumn::make('treatment_cases')
                    ->label('მომსახურება')
                    ->state(function (Visit $record): string {
                        $names = $record->treatmentCaseItems
                            ->map(fn ($item): string => $item->display_name)
                            ->filter()
                            ->unique()
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
                        ? $record->treatmentCaseItems->map(fn ($item): string => $item->display_name)->filter()->implode(', ')
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

    private static function money(mixed $amount, string $currency): string
    {
        return $amount === null ? '—' : Currency::format($amount, $currency);
    }
}
