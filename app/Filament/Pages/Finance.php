<?php

namespace App\Filament\Pages;

use App\Enums\PartnerAccount;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Pages\Concerns\HasBogCurrentBalance;
use App\Filament\Pages\Concerns\HasFinanceOverview;
use App\Models\FinanceTransaction;
use App\Models\LabSalarySettlement;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Services\Bank\BogBankSyncService;
use App\Services\ExpenseDimensions;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use UnitEnum;

class Finance extends Page
{
    use AuthorizesPageAccess;
    use HasBogCurrentBalance;
    use HasFinanceOverview;
    use WithPagination;

    public static function amountTextClasses(string $transactionType): string
    {
        return match ($transactionType) {
            'income' => 'text-emerald-600 dark:text-emerald-400',
            'expense', 'salary_cash', 'owner_withdrawal' => 'text-rose-600 dark:text-rose-400',
            'exchange' => 'text-sky-600 dark:text-sky-400',
            default => 'text-gray-700 dark:text-gray-300',
        };
    }

    public static function typeBadgeClasses(string $transactionType): string
    {
        return match ($transactionType) {
            'income' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
            'expense', 'salary_cash', 'owner_withdrawal' => 'bg-rose-50 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300',
            'exchange' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
            default => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
        };
    }

    protected string $view = 'filament.pages.finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'ფინანსები';

    protected static ?string $title = 'ფინანსები';

    protected static ?int $navigationSort = 31;

    public string $dateFrom = '';

    public string $dateUntil = '';

    public string $period = '1_month';

    public string $type = '';

    public string $category = '';

    public string $paymentMethod = '';

    public string $currency = Currency::DEFAULT;

    public string $cashFlowCurrency = '';

    public string $search = '';

    public string $source = 'all';

    public string $historyMode = 'overview';

    #[Locked]
    public ?string $lastBogSyncAt = null;

    public function mount(): void
    {
        if (static::class === self::class) {
            $this->period = '7_days';
        }
        $this->dateUntil = today()->toDateString();
        $this->applyPeriod($this->period);
        if (static::class === self::class) {
            $this->lastBogSyncAt = app(BogBankSyncService::class)->lastSuccessfulSync()?->format('d.m.Y H:i');
        }
    }

    public function updatedPeriod(string $period): void
    {
        $this->resetPage('overviewPage');
        if ($period !== 'custom') {
            $this->applyPeriod($period);
        }
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    public function updatedDateUntil(): void
    {
        $this->period = 'custom';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->transactionAction('income', 'შემოსავლის დამატება', 'success'),
            $this->transactionAction('expense', 'ხარჯის დამატება', 'danger'),
            ActionGroup::make([
                $this->usdUsageAction(),
                $this->financeTransferAction(),
                Action::make('openingBalances')->label(__('finance-overview.opening_balances'))->color('gray')->url(FinanceOpeningBalances::getUrl()),
            ])->label(__('finance-overview.more'))->button()->color('gray'),
        ];
    }

    public function deleteManualTransaction(int $id, FinanceManager $manager): void
    {
        $manager->delete(FinanceTransaction::query()->findOrFail($id));
    }

    public function resetFilters(): void
    {
        $this->dateUntil = today()->toDateString();
        $this->period = static::class === self::class ? '7_days' : '1_month';
        $this->moneySource = 'all';
        $this->overviewCurrency = '';
        $this->businessSource = 'all';
        $this->overviewCategory = '';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
        $this->applyPeriod($this->period);
        $this->type = '';
        $this->category = '';
        $this->paymentMethod = '';
        $this->currency = Currency::DEFAULT;
        $this->cashFlowCurrency = '';
        $this->search = '';
        $this->source = 'all';
    }

    public function showHistory(string $mode): void
    {
        $nextMode = in_array($mode, ['payments', 'expenses', 'cash_flow'], true) ? $mode : 'overview';

        if ($nextMode === $this->historyMode && $nextMode !== 'overview') {
            $nextMode = 'overview';
        }

        if ($nextMode !== $this->historyMode) {
            $this->category = '';
        }

        $this->historyMode = $nextMode;
    }

    protected function getViewData(): array
    {
        if (static::class === self::class && $this->historyMode === 'overview') {
            return $this->overviewData();
        }
        $entries = collect();

        if ($this->historyMode === 'payments') {
            $payments = $this->includesClinic()
                ? $this->paymentQuery()->latest('payment_date')->latest('id')->limit(200)->get()
                : collect();
            $sales = $this->includesClinic()
                ? $this->productSaleQuery()->latest('sold_at')->latest('id')->limit(200)->get()
                : collect();
            $partnerPayments = $this->includesPartner()
                ? $this->partnerPaymentQuery()->latest('paid_at')->latest('id')->limit(200)->get()
                : collect();

            $entries = $payments->map(function (Payment $payment): array {
                $currencyAmount = (float) $payment->splits->where('currency', $this->currency)->sum('amount');

                return [
                    'key' => 'payment-'.$payment->getKey(), 'manual_id' => null,
                    'source' => 'clinic',
                    'date' => $payment->payment_date, 'type' => 'income', 'category' => 'პაციენტის გადახდა',
                    'source_title' => 'პაციენტის გადახდა',
                    'source_secondary' => $payment->visit?->patient?->full_name,
                    'description' => $payment->comment,
                    'visit_id' => $payment->visit_id,
                    'amount' => $currencyAmount, 'currency' => $this->currency,
                    'display_amount' => $payment->splits
                        ->map(fn (PaymentSplit $split): string => Currency::format($split->amount, $split->currency))
                        ->implode(' + '),
                    'methods' => $payment->splits->pluck('payment_method')->unique()->values()->all(),
                    'created_by' => $payment->creator?->name,
                    'has_time' => false,
                ];
            })->concat($sales->map(fn (ProductSale $sale): array => [
                'key' => 'product-sale-'.$sale->getKey(), 'manual_id' => null,
                'source' => 'clinic',
                'date' => $sale->sold_at, 'type' => 'income', 'category' => 'პროდუქტის გაყიდვა',
                'source_title' => 'პროდუქტის გაყიდვა',
                'source_secondary' => $sale->items->map(fn ($item): string => $item->product->name.' ×'.$item->quantity)->join(', '),
                'description' => $sale->patient?->full_name ?: $sale->note,
                'visit_id' => $sale->visit_id, 'amount' => (float) $sale->total, 'currency' => $sale->currency,
                'methods' => $sale->cashboxTransactions->pluck('payment_method')->unique()->values()->all() ?: [$sale->payment_method],
                'created_by' => $sale->creator?->name,
                'has_time' => $sale->sold_at->format('H:i:s') !== '00:00:00',
            ]))->concat($partnerPayments->map(fn (PartnerPatientPayment $payment): array => [
                'key' => 'partner-payment-'.$payment->getKey(), 'manual_id' => null,
                'source' => 'partner', 'date' => $payment->paid_at, 'type' => 'income',
                'category' => 'პაციენტის გადახდა', 'source_title' => 'პარტნიორის გადახდა',
                'source_secondary' => $payment->patient?->full_name, 'description' => $payment->notes,
                'visit_id' => null, 'amount' => (float) $payment->amount, 'currency' => $payment->currency,
                'methods' => [$payment->payment_method], 'created_by' => null,
                'has_time' => $payment->paid_at->format('H:i:s') !== '00:00:00',
            ]));
        }

        if ($this->historyMode === 'expenses') {
            $manual = $this->manualQuery()->where('type', 'expense')
                ->when($this->source === 'clinic', fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query->whereNull('funding_source')->orWhereIn('funding_source', [FinanceTransaction::FUNDING_CLINIC, FinanceTransaction::FUNDING_MIXED])))
                ->when($this->source === 'partner', fn (Builder $query): Builder => $query
                    ->whereIn('funding_source', [FinanceTransaction::FUNDING_ISRAELI, FinanceTransaction::FUNDING_MIXED]))
                ->latest('transaction_date')->latest('id')->limit(200)->get();
            $partnerExpenses = $this->includesPartner()
                ? $this->partnerExpenseQuery()->latest('transacted_at')->latest('id')->limit(200)->get()
                : collect();
            $entries = $manual->map(fn (FinanceTransaction $transaction): array => [
                'key' => 'finance-'.$transaction->getKey(), 'manual_id' => $transaction->clinic_cash_gel === null ? $transaction->getKey() : null,
                'source' => match ($transaction->funding_source) {
                    FinanceTransaction::FUNDING_ISRAELI => 'partner',
                    FinanceTransaction::FUNDING_MIXED => 'mixed',
                    default => 'clinic',
                },
                'date' => $transaction->transaction_date, 'type' => $transaction->type,
                'category' => app(ExpenseDimensions::class)->summary($transaction),
                'source_title' => $transaction->description ?: '—', 'source_secondary' => null,
                'description' => $transaction->note, 'visit_id' => null,
                'amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
                'methods' => [$transaction->payment_method], 'created_by' => $transaction->creator?->name,
                'has_time' => $transaction->transaction_date->format('H:i:s') !== '00:00:00',
                'movement_kind' => 'expense',
                'operation_signature' => $this->operationSignature($transaction->funding_source ?? 'clinic', $transaction->transaction_date, $transaction->created_by),
                'groupable_expense' => $transaction->clinic_cash_gel === null && array_key_exists((string) $transaction->category, PartnerFinanceTransaction::USD_USAGE_CATEGORIES),
            ])->concat($partnerExpenses->map(fn (PartnerFinanceTransaction $transaction): array => [
                'key' => 'partner-expense-'.$transaction->getKey(), 'manual_id' => null,
                'source' => 'partner', 'date' => $transaction->transacted_at, 'type' => 'expense',
                'category' => app(ExpenseDimensions::class)->summary($transaction),
                'source_title' => $this->expenseCategoryLabel($transaction->category),
                'source_secondary' => $transaction->recipient, 'description' => $transaction->notes, 'visit_id' => null,
                'amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
                'methods' => [$transaction->from_account === 'cash' ? 'cash' : 'bank_transfer'],
                'created_by' => $transaction->creator?->name, 'has_time' => $transaction->transacted_at->format('H:i:s') !== '00:00:00',
                'movement_kind' => 'expense',
                'operation_signature' => $this->operationSignature('partner', $transaction->transacted_at, $transaction->created_by),
                'groupable_expense' => array_key_exists((string) $transaction->category, PartnerFinanceTransaction::USD_USAGE_CATEGORIES),
            ]));

            $entries = $entries->concat($this->linkedExpenseExchangeParents($entries));
        }

        if ($this->historyMode === 'cash_flow') {
            $entries = $this->partnerMovementQuery()
                ->latest('transacted_at')->latest('id')->limit(200)->get()
                ->map(fn (PartnerFinanceTransaction $transaction): array => $this->movementEntry($transaction));
        }

        $entries = $this->historyMode === 'expenses'
            ? $this->groupMovementEntries($entries)
            : $entries->sortByDesc(fn (array $entry): string => $entry['date']->format('Y-m-d H:i:s.u').$entry['key'])->values();

        $sourceBreakdownByCurrency = collect(array_keys(Currency::OPTIONS))->mapWithKeys(function (string $currency): array {
            return [$currency => [
                'clinic' => $this->overviewTotalsForSource('clinic', $currency),
                'partner' => $this->overviewTotalsForSource('partner', $currency),
            ]];
        })->all();
        $totalsByCurrency = collect($sourceBreakdownByCurrency)->mapWithKeys(function (array $breakdown, string $currency): array {
            $sources = $this->source === 'all' ? ['clinic', 'partner'] : [$this->source];
            $income = collect($sources)->sum(fn (string $source): float => $breakdown[$source]['income']);
            $expense = collect($sources)->sum(fn (string $source): float => $breakdown[$source]['expense']);

            return [$currency => [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'result' => round($income - $expense, 2),
            ]];
        })->all();
        $income = $totalsByCurrency[$this->currency]['income'];
        $expense = $totalsByCurrency[$this->currency]['expense'];
        $balanceService = app(FinanceUsdUsageService::class);
        $balancesBySource = [
            'clinic' => $balanceService->cashBalances(PartnerFinanceTransaction::SOURCE_CLINIC),
            'partner' => $balanceService->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI),
        ];
        $availableBalances = $this->source === 'all'
            ? [
                'GEL' => round($balancesBySource['clinic']['GEL'] + $balancesBySource['partner']['GEL'], 2),
                'USD' => round($balancesBySource['clinic']['USD'] + $balancesBySource['partner']['USD'], 2),
            ]
            : $balancesBySource[$this->source];
        $cashOutBreakdownByCurrency = collect(array_keys(Currency::OPTIONS))->mapWithKeys(fn (string $currency): array => [
            $currency => [
                'clinic' => $this->cashOutForSource('clinic', $currency),
                'partner' => $this->cashOutForSource('partner', $currency),
            ],
        ])->all();
        $cashOutByCurrency = collect($cashOutBreakdownByCurrency)->mapWithKeys(function (array $breakdown, string $currency): array {
            $sources = $this->source === 'all' ? ['clinic', 'partner'] : [$this->source];

            return [$currency => round((float) collect($sources)->sum(fn (string $source): float => $breakdown[$source]), 2)];
        })->all();

        return [
            'entries' => $entries,
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'result' => round($income - $expense, 2),
            'totalsByCurrency' => $totalsByCurrency,
            'sourceBreakdownByCurrency' => $sourceBreakdownByCurrency,
            'availableBalances' => $availableBalances,
            'balancesBySource' => $balancesBySource,
            'cashOutByCurrency' => $cashOutByCurrency,
            'cashOutBreakdownByCurrency' => $cashOutBreakdownByCurrency,
            'typeOptions' => FinanceTransaction::TYPES,
            'categoryOptions' => $this->historyMode === 'cash_flow'
                ? ['currency_exchange' => 'ვალუტის გაცვლა', 'transfer' => 'ტრანსფერი', 'owner_withdrawal' => 'მფლობელის გატანა'] + PartnerFinanceTransaction::TRANSFER_CATEGORIES
                : ['patient_payment' => 'პაციენტის გადახდა', 'product_sale' => 'პროდუქტის გაყიდვა'] + FinanceTransaction::CATEGORIES,
            'methodOptions' => $this->historyMode === 'cash_flow'
                ? ['cash' => PartnerAccount::Cash->label(), 'bank_transfer' => PartnerAccount::Bank->label()]
                : PaymentMethod::options(),
            'currencyOptions' => Currency::OPTIONS,
            'sourceOptions' => ['all' => 'ყველა', 'clinic' => 'კლინიკა', 'partner' => 'ისრაელი'],
            'periodOptions' => [
                '7_days' => '7 დღე', '1_month' => '1 თვე', '3_months' => '3 თვე',
                '1_year' => '1 წელი', 'custom' => 'Custom',
            ],
        ];
    }

    protected function restrictReportDates(): bool
    {
        return true;
    }

    private function paymentQuery(?string $currency = null): Builder
    {
        $selectedCurrency = $currency ?? $this->currency;

        return Payment::query()->with(['visit.patient', 'splits', 'creator'])
            ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '>=', $this->dateFrom))
            ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '<=', $this->dateUntil))
            ->whereHas('splits', function (Builder $query) use ($selectedCurrency): void {
                $query->where('currency', $selectedCurrency)
                    ->when(filled($this->paymentMethod), fn (Builder $query): Builder => $query->where('payment_method', $this->paymentMethod));
            })
            ->when($this->type === 'expense', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled($this->category) && $this->category !== 'patient_payment', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $search = '%'.mb_strtolower(trim($this->search)).'%';

                return $query->whereHas('visit.patient', fn (Builder $query): Builder => $query
                    ->whereRaw('LOWER(first_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$search]));
            });
    }

    private function manualQuery(?string $currency = null): Builder
    {
        return FinanceTransaction::query()->with('creator')
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->where('currency', $currency ?? $this->currency)
            ->when(filled($this->type), fn (Builder $query): Builder => $query->where('type', $this->type))
            ->when(filled($this->category), function (Builder $query): Builder {
                return in_array($this->category, ['patient_payment', 'product_sale'], true)
                    ? $query->whereRaw('1 = 0')
                    : $query->where('category', $this->category);
            })
            ->when(filled($this->paymentMethod), fn (Builder $query): Builder => $query->where('payment_method', $this->paymentMethod))
            ->when(filled(trim($this->search)), fn (Builder $query): Builder => $query
                ->where(function (Builder $query): void {
                    $search = '%'.mb_strtolower(trim($this->search)).'%';
                    $query->whereRaw('LOWER(description) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(note) LIKE ?', [$search]);
                }));
    }

    private function productSaleQuery(?string $currency = null): Builder
    {
        return ProductSale::query()->with(['patient', 'items.product', 'cashboxTransactions', 'creator'])
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('sold_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->where('currency', $currency ?? $this->currency)
            ->when($this->type === 'expense', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled($this->category) && $this->category !== 'product_sale', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled($this->paymentMethod), fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                $query->whereHas('cashboxTransactions', fn (Builder $query): Builder => $query->where('payment_method', $this->paymentMethod))
                    ->orWhere(fn (Builder $query): Builder => $query->whereDoesntHave('cashboxTransactions')->where('payment_method', $this->paymentMethod));
            }))
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $search = '%'.mb_strtolower(trim($this->search)).'%';

                return $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(note) LIKE ?', [$search])
                        ->orWhereHas('patient', fn (Builder $query): Builder => $query
                            ->whereRaw('LOWER(first_name) LIKE ?', [$search])->orWhereRaw('LOWER(last_name) LIKE ?', [$search]))
                        ->orWhereHas('items.product', fn (Builder $query): Builder => $query->whereRaw('LOWER(name) LIKE ?', [$search]));
                });
            });
    }

    private function partnerPaymentQuery(?string $currency = null): Builder
    {
        return PartnerPatientPayment::query()->with('patient')
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('paid_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->where('currency', $currency ?? $this->currency)
            ->when(filled($this->paymentMethod), fn (Builder $query): Builder => $query->where('payment_method', $this->paymentMethod))
            ->when(filled($this->category) && $this->category !== 'patient_payment', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $search = '%'.mb_strtolower(trim($this->search)).'%';

                return $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(notes) LIKE ?', [$search])
                        ->orWhereHas('patient', fn (Builder $query): Builder => $query
                            ->whereRaw('LOWER(first_name) LIKE ?', [$search])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$search]));
                });
            });
    }

    private function partnerExpenseQuery(?string $currency = null): Builder
    {
        return PartnerFinanceTransaction::query()->with(['creator', 'labSalarySettlement.technician'])->israeli()
            ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->where('currency', $currency ?? $this->currency)
            ->when(filled($this->category), fn (Builder $query): Builder => $query->where('category', $this->category))
            ->when(filled($this->paymentMethod), function (Builder $query): Builder {
                return $this->paymentMethod === 'cash'
                    ? $query->where('from_account', 'cash')
                    : $query->where('from_account', 'bank');
            })
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $search = '%'.mb_strtolower(trim($this->search)).'%';
                $categoryKeys = $this->matchingKeys(PartnerFinanceTransaction::EXPENSE_CATEGORIES, $this->search);

                return $query->where(function (Builder $query) use ($search, $categoryKeys): void {
                    $query->whereRaw('LOWER(notes) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(recipient) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(category) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(source) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(from_account) LIKE ?', [$search])
                        ->orWhereHas('creator', fn (Builder $query): Builder => $query->whereRaw('LOWER(name) LIKE ?', [$search]))
                        ->orWhereHas('labSalarySettlement.technician', fn (Builder $query): Builder => $query->whereRaw('LOWER(name) LIKE ?', [$search]));

                    if ($categoryKeys !== []) {
                        $query->orWhereIn('category', $categoryKeys);
                    }
                });
            });
    }

    private function partnerMovementQuery(): Builder
    {
        return PartnerFinanceTransaction::query()->with('creator')
            ->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
                PartnerFinanceTransaction::TYPE_SALARY_CASH,
            ])
            ->whereIn('source', match ($this->source) {
                'clinic' => [PartnerFinanceTransaction::SOURCE_CLINIC],
                'partner' => [PartnerFinanceTransaction::SOURCE_ISRAELI],
                default => [PartnerFinanceTransaction::SOURCE_CLINIC, PartnerFinanceTransaction::SOURCE_ISRAELI],
            })
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->when(filled($this->cashFlowCurrency), function (Builder $query): Builder {
                return $query->where(function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)
                            ->where(function (Builder $query): void {
                                $query->where('from_currency', $this->cashFlowCurrency)
                                    ->orWhere('to_currency', $this->cashFlowCurrency);
                            });
                    })->orWhere(function (Builder $query): void {
                        $query->whereIn('type', [PartnerFinanceTransaction::TYPE_TRANSFER, PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL, PartnerFinanceTransaction::TYPE_SALARY_CASH])
                            ->where('currency', $this->cashFlowCurrency);
                    });
                });
            })
            ->when(filled($this->category), function (Builder $query): Builder {
                return match ($this->category) {
                    'currency_exchange' => $query->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE),
                    'transfer' => $query->where('type', PartnerFinanceTransaction::TYPE_TRANSFER),
                    'owner_withdrawal' => $query->where('type', PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL),
                    default => $query->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->where('category', $this->category),
                };
            })
            ->when(filled($this->paymentMethod), function (Builder $query): Builder {
                $account = $this->paymentMethod === 'cash' ? 'cash' : 'bank';

                return $query->where(function (Builder $query) use ($account): void {
                    $query->where('from_account', $account)->orWhere('to_account', $account);
                });
            })
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $term = trim($this->search);
                $search = '%'.mb_strtolower($term).'%';
                $typeKeys = $this->matchingKeys(PartnerFinanceTransaction::TYPES, $term);
                $transferKeys = $this->matchingKeys(PartnerFinanceTransaction::TRANSFER_CATEGORIES, $term);
                if (str_contains('ტრანსფერი', mb_strtolower($term))) {
                    $typeKeys[] = PartnerFinanceTransaction::TYPE_TRANSFER;
                }
                $sourceKeys = collect([
                    PartnerFinanceTransaction::SOURCE_CLINIC => 'კლინიკა',
                    PartnerFinanceTransaction::SOURCE_ISRAELI => 'ისრაელი',
                ])->filter(fn (string $label): bool => str_contains(mb_strtolower($label), mb_strtolower($term)))
                    ->keys()->all();
                $accountKeys = collect(PartnerAccount::options())
                    ->filter(fn (string $label): bool => str_contains(mb_strtolower($label), mb_strtolower($term)))
                    ->keys()->all();

                return $query->where(function (Builder $query) use ($search, $typeKeys, $transferKeys, $sourceKeys, $accountKeys): void {
                    $query->whereRaw('LOWER(notes) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(recipient) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(source) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(type) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(category) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(from_currency) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(to_currency) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(currency) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(from_account) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(to_account) LIKE ?', [$search])
                        ->orWhereHas('creator', fn (Builder $query): Builder => $query->whereRaw('LOWER(name) LIKE ?', [$search]));

                    if ($typeKeys !== []) {
                        $query->orWhereIn('type', $typeKeys);
                    }

                    if ($transferKeys !== []) {
                        $query->orWhereIn('category', $transferKeys);
                    }

                    if ($sourceKeys !== []) {
                        $query->orWhereIn('source', $sourceKeys);
                    }

                    if ($accountKeys !== []) {
                        $query->orWhereIn('from_account', $accountKeys)->orWhereIn('to_account', $accountKeys);
                    }
                });
            });
    }

    /** @return array<string, mixed> */
    private function movementEntry(PartnerFinanceTransaction $transaction): array
    {
        $isSalaryCash = $transaction->type === PartnerFinanceTransaction::TYPE_SALARY_CASH;
        $isExchange = $transaction->type === PartnerFinanceTransaction::TYPE_EXCHANGE;
        $isWithdrawal = $transaction->type === PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL;
        $accountLabels = PartnerAccount::options();
        $destination = ($accountLabels[$transaction->to_account] ?? $transaction->to_account ?: '—')
            .' · '.($isExchange ? $transaction->to_currency : $transaction->currency);

        return [
            'key' => 'movement-'.$transaction->getKey(), 'manual_id' => null,
            'source' => $transaction->source === PartnerFinanceTransaction::SOURCE_ISRAELI ? 'partner' : 'clinic',
            'date' => $transaction->transacted_at, 'type' => 'movement',
            'category' => $isSalaryCash ? __('employees.salary.cash_movement') : ($isExchange ? 'გაცვლა' : ($isWithdrawal ? 'მფლობელის გატანა' : 'ტრანსფერი')),
            'source_title' => $isSalaryCash ? __('employees.salary.cash_movement') : ($isExchange ? 'USD → GEL' : ($isWithdrawal ? 'მფლობელის გატანა' : 'ტრანსფერი')),
            'source_secondary' => $isExchange
                ? $destination
                : ($isWithdrawal ? $transaction->recipient : (PartnerFinanceTransaction::TRANSFER_CATEGORIES[$transaction->category] ?? $destination)),
            'description' => $transaction->notes,
            'visit_id' => null,
            'amount' => (float) ($isExchange ? $transaction->to_amount : $transaction->amount),
            'currency' => $isExchange ? $transaction->to_currency : $transaction->currency,
            'display_amount' => $isExchange
                ? Currency::format($transaction->from_amount, $transaction->from_currency).' → '.Currency::format($transaction->to_amount, $transaction->to_currency)
                : Currency::format($transaction->amount, $transaction->currency),
            'from_display' => ($accountLabels[$transaction->from_account] ?? $transaction->from_account ?: '—')
                .' · '.($isExchange ? $transaction->from_currency : $transaction->currency),
            'to_display' => $isWithdrawal ? 'მფლობელი' : $destination,
            'methods' => [], 'created_by' => $transaction->creator?->name,
            'has_time' => $transaction->transacted_at->format('H:i:s') !== '00:00:00',
            'movement_kind' => $isSalaryCash ? 'salary_cash' : ($isExchange ? 'exchange' : ($isWithdrawal ? 'owner_withdrawal' : 'transfer')),
            'operation_signature' => $this->operationSignature(
                $transaction->source === PartnerFinanceTransaction::SOURCE_ISRAELI ? 'partner' : 'clinic',
                $transaction->transacted_at,
                $transaction->created_by,
            ),
            'groupable_expense' => false,
            'exchange_received_amount' => $isExchange ? (float) $transaction->to_amount : null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $expenses
     * @return Collection<int, array<string, mixed>>
     */
    private function linkedExpenseExchangeParents(Collection $expenses): Collection
    {
        $signatures = $expenses
            ->filter(fn (array $expense): bool => ($expense['groupable_expense'] ?? false)
                && $expense['currency'] === 'GEL')
            ->pluck('operation_signature')
            ->filter()
            ->unique();

        if ($signatures->isEmpty()) {
            return collect();
        }

        return $this->overviewMovementRecords()
            ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)
            ->filter(fn (PartnerFinanceTransaction $exchange): bool => $exchange->from_currency === 'USD'
                && $exchange->to_currency === 'GEL')
            ->map(fn (PartnerFinanceTransaction $exchange): array => $this->movementEntry($exchange))
            ->filter(fn (array $exchange): bool => $signatures->contains($exchange['operation_signature']))
            ->values();
    }

    /** @param Collection<int, array<string, mixed>> $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function groupMovementEntries(Collection $entries): Collection
    {
        $sorted = $entries->sortByDesc(fn (array $entry): string => $entry['date']->format('Y-m-d H:i:s.u').$entry['key'])->values();
        $parentSignatures = $sorted
            ->where('movement_kind', 'exchange')
            ->pluck('operation_signature')
            ->filter()->unique();
        $children = $sorted->filter(fn (array $entry): bool => ($entry['groupable_expense'] ?? false)
            && $parentSignatures->contains($entry['operation_signature'] ?? null));
        $result = collect();

        foreach ($sorted as $entry) {
            if ($children->contains(fn (array $child): bool => $child['key'] === $entry['key'])) {
                continue;
            }

            $entry['is_group_parent'] = ($entry['movement_kind'] ?? null) === 'exchange'
                && $children->contains(fn (array $child): bool => $child['operation_signature'] === $entry['operation_signature']);
            $entry['is_group_child'] = false;
            $result->push($entry);

            if ($entry['is_group_parent']) {
                $linkedChildren = $children->where('operation_signature', $entry['operation_signature']);
                $entryIndex = $result->keys()->last();
                $entry['group_remaining'] = round(max(
                    (float) ($entry['exchange_received_amount'] ?? 0) - (float) $linkedChildren->sum('amount'),
                    0,
                ), 2);
                $result->put($entryIndex, $entry);

                $linkedChildren->each(function (array $child) use ($result): void {
                    $child['is_group_parent'] = false;
                    $child['is_group_child'] = true;
                    $result->push($child);
                });
            }
        }

        return $result->values();
    }

    private function operationSignature(string $source, Carbon $date, ?int $createdBy): string
    {
        return implode('|', [$source, $date->format('Y-m-d H:i:s.u'), $createdBy ?? 'system']);
    }

    /** @return Collection<int, PartnerFinanceTransaction> */
    private function overviewMovementRecords(): Collection
    {
        return PartnerFinanceTransaction::query()
            ->whereIn('source', match ($this->source) {
                'clinic' => [PartnerFinanceTransaction::SOURCE_CLINIC],
                'partner' => [PartnerFinanceTransaction::SOURCE_ISRAELI],
                default => [PartnerFinanceTransaction::SOURCE_CLINIC, PartnerFinanceTransaction::SOURCE_ISRAELI],
            })
            ->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
                PartnerFinanceTransaction::TYPE_SALARY_CASH,
            ])
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ]))
            ->orderByDesc('transacted_at')
            ->get();
    }

    /** @param Collection<int, PartnerFinanceTransaction> $movements
     * @return array<int, array{source: string, usd_amount: float, gel_received: float, gel_used: float, gel_remaining: float, expenses: array<int, array{label: string, amount: float}>}>
     */
    private function exchangeExpenseExplanations(Collection $movements): array
    {
        $exchanges = $movements
            ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)
            ->filter(fn (PartnerFinanceTransaction $exchange): bool => $exchange->from_currency === 'USD'
                && $exchange->to_currency === 'GEL');
        if ($exchanges->isEmpty()) {
            return [];
        }

        $range = [
            Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
            Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
        ];
        $partnerExpenses = $exchanges->contains('source', PartnerFinanceTransaction::SOURCE_ISRAELI)
            ? PartnerFinanceTransaction::query()
                ->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->where('currency', 'GEL')
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', $range))
                ->get()
                ->map(fn (PartnerFinanceTransaction $expense): array => [
                    'signature' => $this->operationSignature(
                        $expense->source === PartnerFinanceTransaction::SOURCE_ISRAELI ? 'partner' : 'clinic',
                        $expense->transacted_at,
                        $expense->created_by,
                    ),
                    'label' => $this->expenseCategoryLabel($expense->category),
                    'amount' => (float) $expense->amount,
                ])
            : collect();
        $clinicExpenses = $exchanges->contains('source', PartnerFinanceTransaction::SOURCE_CLINIC)
            ? FinanceTransaction::query()
                ->where('type', 'expense')
                ->where('currency', 'GEL')
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
                ->get()
                ->map(fn (FinanceTransaction $expense): array => [
                    'signature' => $this->operationSignature('clinic', $expense->transaction_date, $expense->created_by),
                    'label' => $this->expenseCategoryLabel($expense->category),
                    'amount' => (float) $expense->amount,
                ])
            : collect();
        $expenses = $partnerExpenses->concat($clinicExpenses)->groupBy('signature');

        return $exchanges
            ->map(function (PartnerFinanceTransaction $exchange) use ($expenses): ?array {
                $source = $exchange->source === PartnerFinanceTransaction::SOURCE_ISRAELI ? 'partner' : 'clinic';
                $linked = $expenses->get($this->operationSignature($source, $exchange->transacted_at, $exchange->created_by), collect());
                if ($linked->isEmpty()) {
                    return null;
                }

                $used = round((float) $linked->sum('amount'), 2);

                return [
                    'source' => $source,
                    'usd_amount' => (float) $exchange->from_amount,
                    'gel_received' => (float) $exchange->to_amount,
                    'gel_used' => $used,
                    'gel_remaining' => round(max((float) $exchange->to_amount - $used, 0), 2),
                    'expenses' => $linked->groupBy('label')->map(fn (Collection $items, string $label): array => [
                        'label' => $label,
                        'amount' => round((float) $items->sum('amount'), 2),
                    ])->values()->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function expenseCategoryLabel(?string $category): string
    {
        return match ($category) {
            'salary', 'lab_salary' => 'ხელფასი',
            'doctor_salary' => 'ექიმის ხელფასი',
            'supplier', 'laboratory' => 'მომწოდებელი',
            'materials' => 'მასალები / მარაგები',
            'equipment' => 'აღჭურვილობა',
            'other', 'other_expense' => 'სხვა',
            default => FinanceTransaction::CATEGORIES[$category] ?? ExpenseCategoryForm::label($category)
                ?? PartnerFinanceTransaction::EXPENSE_CATEGORIES[$category]
                ?? ($category ?: 'ხარჯი'),
        };
    }

    /** @param array<string, string> $options
     * @return array<int, string>
     */
    private function matchingKeys(array $options, string $term): array
    {
        $needle = mb_strtolower(trim($term));

        return collect($options)
            ->filter(fn (string $label, string $key): bool => str_contains(mb_strtolower($label), $needle)
                || str_contains(mb_strtolower($key), $needle))
            ->keys()->values()->all();
    }

    private function includesClinic(): bool
    {
        return in_array($this->source, ['all', 'clinic'], true);
    }

    private function includesPartner(): bool
    {
        return in_array($this->source, ['all', 'partner'], true);
    }

    /** @return array{income: float, expense: float} */
    private function overviewTotalsForSource(string $source, string $currency): array
    {
        $range = [
            Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
            Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
        ];

        if ($source === 'partner') {
            $allocatedSalaryExpense = $currency === 'GEL'
                ? (float) FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
                    ->where('type', 'expense')->sum('israeli_cash_gel')
                : 0;

            return [
                'income' => round((float) PartnerPatientPayment::query()
                    ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('paid_at', $range))->where('currency', $currency)->sum('amount'), 2),
                'expense' => round((float) PartnerFinanceTransaction::query()->israeli()
                    ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                    ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', $range))->where('currency', $currency)->sum('amount')
                    + $allocatedSalaryExpense, 2),
            ];
        }

        $income = (float) PaymentSplit::query()
            ->where('currency', $currency)
            ->whereHas('payment', fn (Builder $query): Builder => $query
                ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '>=', $this->dateFrom))
                ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '<=', $this->dateUntil)))
            ->sum('amount')
            + (float) ProductSale::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('sold_at', $range))
                ->where('currency', $currency)->sum('total')
            + (float) FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
                ->where('type', 'income')->where('currency', $currency)->sum('amount');
        $expense = (float) FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
            ->where('type', 'expense')->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(COALESCE(clinic_cash_gel, amount)), 0) as total')
            ->value('total');

        return ['income' => round($income, 2), 'expense' => round($expense, 2)];
    }

    private function cashOutForSource(string $source, string $currency): float
    {
        $range = [
            Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
            Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
        ];

        if ($source === 'clinic') {
            $expenses = FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
                ->where('type', 'expense')->where('currency', $currency)
                ->where('payment_method', 'cash')->where('cash_source', 'current_cashier')
                ->selectRaw('COALESCE(SUM(COALESCE(clinic_cash_gel, amount)), 0) as total')
                ->value('total');
            $ledgerSource = PartnerFinanceTransaction::SOURCE_CLINIC;
        } else {
            $expenses = (float) PartnerFinanceTransaction::query()->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->where('from_account', 'cash')
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', $range))->where('currency', $currency)->sum('amount');
            if ($currency === 'GEL') {
                $expenses += (float) FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', $range))
                    ->where('type', 'expense')->sum('israeli_cash_gel');
            }
            $ledgerSource = PartnerFinanceTransaction::SOURCE_ISRAELI;
        }

        $movements = PartnerFinanceTransaction::query()->where('source', $ledgerSource)
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', $range))
            ->where('from_account', 'cash')
            ->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
            ])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? AND from_currency = ? THEN from_amount WHEN type <> ? AND currency = ? THEN amount ELSE 0 END), 0) as total',
                [PartnerFinanceTransaction::TYPE_EXCHANGE, $currency, PartnerFinanceTransaction::TYPE_EXCHANGE, $currency],
            )->value('total');

        return round((float) $expenses + (float) $movements, 2);
    }

    private function applyPeriod(string $period): void
    {
        $until = filled($this->dateUntil)
            ? Carbon::parse($this->dateUntil, config('app.timezone'))->startOfDay()
            : today();

        $this->dateUntil = $until->toDateString();
        $this->dateFrom = match ($period) {
            '7_days' => $until->copy()->subDays(6)->toDateString(),
            '3_months' => $until->copy()->subMonths(3)->addDay()->toDateString(),
            '1_year' => $until->copy()->subYear()->addDay()->toDateString(),
            default => $until->copy()->subMonth()->addDay()->toDateString(),
        };
    }

    private function transactionAction(string $type, string $label, string $color): Action
    {
        return Action::make('add_'.$type)->label($label)->color($color)->size('sm')
            ->schema([
                DateTimePicker::make('transaction_date')->label('თარიღი / დრო')->timezone(config('app.timezone'))->default(now())->required(),
                ...($type === 'expense' ? ExpenseCategoryForm::schema() : [Select::make('category')->label('კატეგორია')->options(FinanceTransaction::CATEGORIES)->searchable()->required()]),
                TextInput::make('description')->label('აღწერა / წყარო')->maxLength(255),
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->required(),
                Select::make('currency')->label('ვალუტა')->options(Currency::OPTIONS)->default(Currency::DEFAULT)->required(),
                Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())->default('cash')->live()->required(),
                Select::make('cash_source')->label('ნაღდი თანხის წყარო')->options(
                    $type === 'expense'
                        ? FinanceTransaction::CASH_SOURCES
                        : array_diff_key(FinanceTransaction::CASH_SOURCES, ['israeli' => true]),
                )
                    ->default('current_cashier')->visible(fn (Get $get): bool => $get('payment_method') === 'cash')
                    ->required(fn (Get $get): bool => $get('payment_method') === 'cash'),
                Textarea::make('note')->label('შენიშვნა')->rows(2),
            ])
            ->action(fn (array $data, FinanceManager $manager) => $manager->create([...$data, 'type' => $type]));
    }

    private function usdUsageAction(): Action
    {
        $exchangeVisible = fn (Get $get): bool => in_array($get('usage_type'), ['exchange_only', 'exchange_and_spend'], true);
        $directExpenseVisible = fn (Get $get): bool => $get('usage_type') === 'direct_usd_expense';

        return Action::make('usdUsage')
            ->label('USD Usage')
            ->color('warning')
            ->modalHeading('USD Usage')
            ->modalSubmitActionLabel('დაფიქსირება')
            ->schema([
                Select::make('source')->label('წყარო')->options([
                    PartnerFinanceTransaction::SOURCE_CLINIC => 'კლინიკა',
                    PartnerFinanceTransaction::SOURCE_ISRAELI => 'ისრაელი',
                ])->default(PartnerFinanceTransaction::SOURCE_ISRAELI)->required()->native(false),
                Select::make('usage_type')->label('გამოყენების ტიპი')->options([
                    'exchange_and_spend' => 'გაცვლა და დახარჯვა',
                    'exchange_only' => 'მხოლოდ გაცვლა',
                    'direct_usd_expense' => 'პირდაპირი USD ხარჯი',
                ])->default('exchange_and_spend')->live()->required()->native(false),
                TextInput::make('usd_amount')->label('გამოყენებული USD')->numeric()->minValue(0.01)->step(0.01)->prefix('$')
                    ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncReceivedGel($get, $set))->required(),
                TextInput::make('exchange_rate')->label('გაცვლის კურსი')->numeric()->minValue(0.000001)->step(0.000001)
                    ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncReceivedGel($get, $set))
                    ->visible($exchangeVisible)->required($exchangeVisible),
                TextInput::make('received_gel_amount')->label('მიღებული GEL')->numeric()->minValue(0.01)->step(0.01)->suffix('₾')
                    ->readOnly()->visible($exchangeVisible)->required($exchangeVisible),
                DateTimePicker::make('transacted_at')->label('თარიღი / დრო')->default(now())->required(),
                Repeater::make('expenses')->label('GEL ხარჯები')->schema([
                    ...ExpenseCategoryForm::schema(),
                    TextInput::make('recipient')->label('მიმღები / თანამშრომელი')->maxLength(255),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->suffix('₾')->required(),
                    Select::make('lab_salary_settlement_id')->label('ლაბის ხელფასის ჩანაწერი (არასავალდებულო)')
                        ->options(fn (): array => LabSalarySettlement::query()->with('technician')
                            ->where('status', 'confirmed')->whereNull('actual_paid_gel')->latest('settled_at')->limit(100)->get()
                            ->mapWithKeys(fn (LabSalarySettlement $settlement): array => [
                                $settlement->getKey() => $settlement->technician->name.' · '.Currency::format($settlement->salary_total, 'GEL'),
                            ])->all())
                        ->searchable()->native(false),
                    Textarea::make('notes')->label('შენიშვნა')->rows(1),
                ])->columns(2)->defaultItems(1)->addActionLabel('+ ხარჯი')
                    ->visible(fn (Get $get): bool => $get('usage_type') === 'exchange_and_spend'),
                ...array_map(fn ($field) => $field->visible($directExpenseVisible), ExpenseCategoryForm::schema()),
                TextInput::make('recipient')->label('მიმღები / თანამშრომელი')->maxLength(255)
                    ->visible($directExpenseVisible),
                Textarea::make('notes')->label('შენიშვნა')->rows(2),
            ])
            ->action(fn (array $data, FinanceUsdUsageService $service) => $service->record($data));
    }

    private static function syncReceivedGel(Get $get, Set $set): void
    {
        $set('received_gel_amount', FinanceUsdUsageService::calculateReceivedGel(
            $get('usd_amount'),
            $get('exchange_rate'),
        ));
    }

    private function financeTransferAction(): Action
    {
        return Action::make('financeTransfer')
            ->label('თანხის გადატანა')
            ->color('gray')
            ->modalHeading('თანხის გადატანა')
            ->modalSubmitActionLabel('დაფიქსირება')
            ->schema([
                Select::make('source')->label('წყარო')->options([
                    PartnerFinanceTransaction::SOURCE_CLINIC => 'კლინიკა',
                    PartnerFinanceTransaction::SOURCE_ISRAELI => 'ისრაელი',
                ])->default(PartnerFinanceTransaction::SOURCE_ISRAELI)->required()->native(false),
                Select::make('currency')->label('ვალუტა')->options(Currency::OPTIONS)->default('GEL')->required()->native(false),
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->required(),
                Select::make('category')->label('გადატანის ტიპი')->options(PartnerFinanceTransaction::TRANSFER_CATEGORIES)
                    ->default('bank_deposit')->required()->native(false),
                DateTimePicker::make('transacted_at')->label('თარიღი / დრო')->default(now())->required(),
                Textarea::make('notes')->label('შენიშვნა')->rows(2),
            ])
            ->action(fn (array $data, FinanceUsdUsageService $service) => $service->transfer($data));
    }
}
