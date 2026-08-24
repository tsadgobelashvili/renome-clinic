<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\Payment;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'გადახდების ისტორია';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['visit.doctor', 'splits']))
            ->columns([
                TextColumn::make('payment_date')->label('თარიღი')->date('d.m.y')->sortable(),
                TextColumn::make('amount')->label('თანხა')
                    ->formatStateUsing(fn ($state, Payment $record): string => Currency::format($state, $record->currency)),
                TextColumn::make('method_display')->label('მეთოდი')->wrap(),
                TextColumn::make('visit.visit_date')->label('ვიზიტი')->date('d.m.y'),
            ])
            ->recordActions([
                Action::make('editVisit')
                    ->label('რედაქტირება')
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton()
                    ->tooltip('ვიზიტისა და გადახდის ნახვა')
                    ->url(fn (Payment $record): string => VisitResource::getUrl('edit', [
                        'record' => $record->visit_id,
                    ])),
                Action::make('auditHistory')
                    ->label('ისტორია')
                    ->icon('heroicon-o-clock')
                    ->iconButton()
                    ->tooltip('ცვლილებების ისტორია')
                    ->modalHeading('გადახდის ცვლილებების ისტორია')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('დახურვა')
                    ->schema([
                        TextEntry::make('audit_history')
                            ->hiddenLabel()
                            ->state(fn (Payment $record): array => $record->audits()
                                ->with('user')
                                ->get()
                                ->map(fn ($audit): string => $audit->created_at->format('d.m.y H:i')
                                    .' — '.($audit->user?->name ?? 'სისტემა')
                                    .' — '.match ($audit->action) {
                                        'created' => 'შექმნა',
                                        'updated' => 'რედაქტირება',
                                        'deleted' => 'წაშლა',
                                        'restored' => 'აღდგენა',
                                        'split_created' => 'მეთოდის დამატება',
                                        'split_updated', 'splits_updated' => 'მეთოდის ცვლილება',
                                        'split_deleted' => 'მეთოდის წაშლა',
                                        default => $audit->action,
                                    })
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('ისტორია ჯერ არ არის.'),
                    ]),
            ])
            ->recordUrl(fn (Payment $record): string => VisitResource::getUrl('edit', [
                'record' => $record->visit_id,
            ]))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('გადახდები ჯერ არ არის.')
            ->defaultSort('payment_date', 'desc');
    }
}
