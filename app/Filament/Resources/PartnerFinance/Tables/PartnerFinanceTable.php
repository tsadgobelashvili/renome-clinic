<?php

namespace App\Filament\Resources\PartnerFinance\Tables;

use App\Enums\PartnerAccount;
use App\Enums\PaymentMethod;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Services\ExpenseDimensions;
use App\Services\PartnerFinanceSummary;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
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
            ->modifyQueryUsing(fn (Builder $query, ListPartnerFinance $livewire): Builder => $query
                ->select('partner_finance_entries.*')
                ->selectSub(PartnerFinanceTransaction::select('expense_direction_id')->whereColumn('id', 'partner_finance_entries.source_id')->whereRaw("partner_finance_entries.source_type = 'transaction'"), 'expense_direction_id')
                ->selectSub(PartnerFinanceTransaction::select('expense_type_id')->whereColumn('id', 'partner_finance_entries.source_id')->whereRaw("partner_finance_entries.source_type = 'transaction'"), 'expense_type_id')
                ->where('source', PartnerFinanceTransaction::SOURCE_ISRAELI)
                ->when($livewire->dateFrom, fn (Builder $query): Builder => $query->whereDate('transacted_at', '>=', $livewire->dateFrom))
                ->when($livewire->dateUntil, fn (Builder $query): Builder => $query->whereDate('transacted_at', '<=', $livewire->dateUntil))
                ->when($livewire->movementType, fn (Builder $query): Builder => $query->where('transaction_type', $livewire->movementType))
                ->with(['patient', 'creator']))
            ->columns([
                TextColumn::make('transacted_at')->label('თარიღი / დრო')
                    ->formatStateUsing(fn ($state): string => $state->format('H:i:s') === '00:00:00'
                        ? $state->format('d.m.Y')
                        : $state->format('d.m.Y H:i'))
                    ->sortable(),
                TextColumn::make('transaction_type')
                    ->label('ტიპი')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'payment'
                        ? 'შემოსავალი'
                        : (PartnerFinanceTransaction::TYPES[$state] ?? $state))
                    ->color(fn (string $state): string => self::semanticColor($state)),
                TextColumn::make('recipient_display')->label('მიმღები / პაციენტი')
                    ->state(fn (PartnerFinanceEntry $record): string => $record->patient?->full_name
                        ?: ($record->recipient ?: '—'))
                    ->url(fn (PartnerFinanceEntry $record): ?string => self::recipientUrl($record)),
                TextColumn::make('category_display')->label('კატეგორია')
                    ->state(fn (PartnerFinanceEntry $record): string => self::category($record)),
                TextColumn::make('movement_display')->label('მოძრაობა / მეთოდი')
                    ->state(fn (PartnerFinanceEntry $record): string => self::movement($record)),
                TextColumn::make('display_amount')
                    ->label('თანხა')
                    ->state(fn (PartnerFinanceEntry $record): string => self::amount($record))
                    ->color(fn (PartnerFinanceEntry $record): string => self::semanticColor($record->transaction_type))
                    ->weight('semibold'),
                TextColumn::make('creator.name')->label('შექმნა')->placeholder('—'),
                TextColumn::make('notes')->label('შენიშვნა')->placeholder('—')->limit(45)->tooltip(fn ($state): ?string => $state),
            ])
            ->filters([
                Filter::make('transacted_at')->label('პერიოდი')->schema([
                    DatePicker::make('from')->label('დან')->displayFormat('d.m.Y'),
                    DatePicker::make('until')->label('მდე')->displayFormat('d.m.Y'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transacted_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transacted_at', '<=', $date))),
                SelectFilter::make('transaction_type')->label('მოძრაობის ტიპი')->placeholder('ყველა')->options([
                    'payment' => 'შემოსავალი',
                    PartnerFinanceTransaction::TYPE_EXPENSE => 'ხარჯი',
                    PartnerFinanceTransaction::TYPE_EXCHANGE => 'გაცვლა',
                    PartnerFinanceTransaction::TYPE_TRANSFER => 'ტრანსფერი',
                    PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL => 'მფლობელის გატანა',
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
            ->filtersTriggerAction(fn ($action) => $action->hidden())
            ->filters([])
            ->recordUrl(null)
            ->defaultSort('transacted_at', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    public static function semanticColor(string $transactionType): string
    {
        return match ($transactionType) {
            'payment' => 'success',
            PartnerFinanceTransaction::TYPE_EXPENSE,
            PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL => 'danger',
            PartnerFinanceTransaction::TYPE_EXCHANGE,
            PartnerFinanceTransaction::TYPE_TRANSFER => 'info',
            default => 'gray',
        };
    }

    public static function recipientUrl(PartnerFinanceEntry $record): ?string
    {
        if ($record->transaction_type !== 'payment' || ! $record->patient_id || ! $record->patient) {
            return null;
        }

        return PartnerPatientResource::getUrl('view', ['record' => $record->patient_id]);
    }

    private static function category(PartnerFinanceEntry $record): string
    {
        if ($record->transaction_type === PartnerFinanceTransaction::TYPE_EXPENSE && ($record->expense_direction_id || $record->expense_type_id)) {
            return app(ExpenseDimensions::class)->summary($record);
        }

        return match ($record->transaction_type) {
            'payment' => 'პაციენტის გადახდა',
            PartnerFinanceTransaction::TYPE_EXPENSE => PartnerFinanceTransaction::EXPENSE_CATEGORIES[$record->category]
                ?? FinanceTransaction::CATEGORIES[$record->category] ?? ExpenseCategoryForm::label($record->category)
                ?? 'ხარჯი',
            PartnerFinanceTransaction::TYPE_TRANSFER => PartnerFinanceTransaction::TRANSFER_CATEGORIES[$record->category] ?? 'ტრანსფერი',
            PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL => 'მფლობელის გატანა',
            PartnerFinanceTransaction::TYPE_EXCHANGE => 'ვალუტის გაცვლა',
            PartnerFinanceTransaction::TYPE_SALARY_CASH => __('employees.salary.cash_movement'),
            default => '—',
        };
    }

    private static function movement(PartnerFinanceEntry $record): string
    {
        if ($record->transaction_type === 'payment') {
            return PaymentMethod::labelFor($record->payment_method);
        }

        $from = PartnerAccount::tryFrom((string) $record->from_account)?->label() ?? '—';
        $to = PartnerAccount::tryFrom((string) $record->to_account)?->label();

        if ($record->transaction_type === PartnerFinanceTransaction::TYPE_EXCHANGE) {
            return $from.' · '.$record->from_currency.' → '.($to ?: $from).' · '.$record->to_currency;
        }

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
