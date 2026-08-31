<?php

namespace App\Filament\Resources\PartnerFinance\Tables;

use App\Enums\PartnerAccount;
use App\Enums\PaymentMethod;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Services\PartnerFinanceSummary;
use App\Support\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerFinanceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.resources.partner-finance.summary', [
                'summary' => app(PartnerFinanceSummary::class),
            ]))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('patient'))
            ->columns([
                TextColumn::make('transacted_at')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('transaction_type')
                    ->label('ტიპი')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'payment'
                        ? 'პაციენტის გადახდა'
                        : (PartnerFinanceTransaction::TYPES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'payment' => 'success',
                        PartnerFinanceTransaction::TYPE_EXPENSE => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('patient.full_name')->label('პაციენტი')->placeholder('—'),
                TextColumn::make('details')
                    ->label('აღწერა')
                    ->state(fn (PartnerFinanceEntry $record): string => self::details($record))
                    ->wrap(),
                TextColumn::make('accounts')
                    ->label('ანგარიში')
                    ->state(fn (PartnerFinanceEntry $record): string => self::accounts($record)),
                TextColumn::make('display_amount')
                    ->label('თანხა')
                    ->state(fn (PartnerFinanceEntry $record): string => self::amount($record))
                    ->weight('semibold'),
            ])
            ->filters([
                Filter::make('transacted_at')->label('პერიოდი')->schema([
                    DatePicker::make('from')->label('დან')->displayFormat('d.m.Y'),
                    DatePicker::make('until')->label('მდე')->displayFormat('d.m.Y'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transacted_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transacted_at', '<=', $date))),
                SelectFilter::make('transaction_type')->label('ტიპი')->options([
                    'payment' => 'პაციენტის გადახდა',
                    ...PartnerFinanceTransaction::TYPES,
                ]),
                SelectFilter::make('currency')->label('ვალუტა')->options(array_combine(
                    array_keys(Currency::OPTIONS),
                    array_keys(Currency::OPTIONS),
                ))->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, string $currency): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('currency', $currency)
                        ->orWhere('from_currency', $currency)
                        ->orWhere('to_currency', $currency)),
                )),
                SelectFilter::make('account')->label('ანგარიში')->options(PartnerAccount::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $account): Builder => $query->where(fn (Builder $query): Builder => $query
                            ->where('from_account', $account)
                            ->orWhere('to_account', $account)),
                    )),
                SelectFilter::make('patient_id')->label('პაციენტი')
                    ->relationship(
                        'patient',
                        'first_name',
                        fn (Builder $query): Builder => $query
                            ->whereHas('patientGroup', fn (Builder $query): Builder => $query->where(
                                'slug',
                                PatientGroup::ISRAEL_PARTNER_SLUG,
                            ))
                            ->orderBy('first_name')
                            ->orderBy('last_name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name']),
            ])
            ->recordUrl(null)
            ->defaultSort('transacted_at', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    private static function details(PartnerFinanceEntry $record): string
    {
        $main = match ($record->transaction_type) {
            'payment' => PaymentMethod::labelFor($record->payment_method),
            PartnerFinanceTransaction::TYPE_EXPENSE => PartnerFinanceTransaction::EXPENSE_CATEGORIES[$record->category] ?? 'ხარჯი',
            PartnerFinanceTransaction::TYPE_TRANSFER => 'თანხის გადატანა',
            PartnerFinanceTransaction::TYPE_EXCHANGE => 'კურსი '.number_format((float) $record->exchange_rate, 6),
            default => '—',
        };

        return $main.(filled($record->notes) ? ' — '.$record->notes : '');
    }

    private static function accounts(PartnerFinanceEntry $record): string
    {
        $from = PartnerAccount::tryFrom((string) $record->from_account)?->label() ?? '—';
        $to = PartnerAccount::tryFrom((string) $record->to_account)?->label();

        return $to ? $from.' → '.$to : $from;
    }

    private static function amount(PartnerFinanceEntry $record): string
    {
        if ($record->transaction_type === PartnerFinanceTransaction::TYPE_EXCHANGE) {
            return Currency::format($record->from_amount, $record->from_currency)
                .' → '.Currency::format($record->to_amount, $record->to_currency);
        }

        return Currency::format($record->amount, $record->currency);
    }
}
