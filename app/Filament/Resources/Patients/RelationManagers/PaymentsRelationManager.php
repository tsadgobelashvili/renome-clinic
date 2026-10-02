<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Patients\Actions\ViewPatientVisitAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Support\PatientPaymentHistory;
use App\Models\Payment;
use App\Support\Currency;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('patient-profile.payments');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PatientResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        abort_unless(PatientResource::canView($this->getOwnerRecord()), 403);

        return $table->relationship(null)
            ->query(fn () => PatientPaymentHistory::query($this->getOwnerRecord()))
            ->columns([
                TextColumn::make('payment_date')->label(__('patient-profile.date'))->date('d.m.Y'),
                TextColumn::make('amount')->label(__('patient-profile.amount'))->alignEnd()
                    ->formatStateUsing(fn ($state, Payment $record): string => Currency::format($state, $record->currency)),
                TextColumn::make('method')->label(__('patient-profile.method'))->wrap()
                    ->state(fn (Payment $record): string => $record->source === 'clinic' && $record->splits->isNotEmpty()
                        ? $record->method_display
                        : PaymentMethod::labelFor($record->payment_method).' '.Currency::format($record->amount, $record->currency)),
                TextColumn::make('linked_visit')->label(__('patient-profile.visit'))
                    ->state(fn (Payment $record): string => $record->visit
                        ? '#'.$record->visit_id.' · '.$record->visit->visit_date?->format('d.m.Y')
                        : __('patient-profile.payment').' · '.$record->payment_date?->format('d.m.Y')),
                TextColumn::make('comment')->label(__('patient-profile.notes'))->wrap()->placeholder('—'),
            ])
            ->recordUrl(null)
            ->recordAction(fn (Payment $record): ?string => $record->visit ? 'visitDetails' : null)
            ->recordActions([
                ViewPatientVisitAction::make($this->getOwnerRecord(), fn (Payment $record) => $record->visit_id)
                    ->visible(fn (Payment $record): bool => $record->visit !== null),
            ])
            ->defaultSort(fn ($query) => $query->orderByDesc('payment_date')->orderByDesc('id'))
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('patient-profile.no_payments'));
    }
}
