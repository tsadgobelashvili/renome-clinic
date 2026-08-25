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
                ->with(['patient', 'treatmentCaseItems.treatmentCase'])
                ->withSum('payments', 'amount'))
            ->columns([
                TextColumn::make('visit_date')->label('ვიზიტის თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('patient.full_name')->label('პაციენტი')->searchable(['first_name', 'last_name']),
                TextColumn::make('treatment_cases')
                    ->label('მკურნალობის ქეისები')
                    ->state(fn (Visit $record): array => $record->treatmentCaseItems
                        ->map(fn ($item): string => $item->display_name
                            ." × {$item->quantity}"
                            .(filled($item->teeth) ? " — {$item->teeth}" : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted(),
                TextColumn::make('comment')->label('კომენტარი')->limit(60)->wrap(),
                TextColumn::make('total_price')->label('ღირებულება')->formatStateUsing(
                    fn ($state, Visit $record): string => self::money($state, $record->currency),
                ),
                TextColumn::make('discount_display')->label('ფასდაკლება')->placeholder('0.00 ₾'),
                TextColumn::make('paid_amount')->label('გადახდილი')->formatStateUsing(
                    fn ($state, Visit $record): string => self::money($state, $record->currency),
                ),
                TextColumn::make('remaining_amount')
                    ->label('დარჩენილი')
                    ->formatStateUsing(fn ($state, Visit $record): string => self::money($state, $record->currency))
                    ->badge()
                    ->color(fn ($state): string => ($state !== null) && ((float) $state <= 0) ? 'success' : 'warning'),
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
