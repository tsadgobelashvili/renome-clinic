<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Services\FinanceManager;
use App\Support\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class Finance extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    protected string $view = 'filament.pages.finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'ფინანსები';

    protected static ?string $title = 'ფინანსები';

    protected static ?int $navigationSort = 31;

    public string $dateFrom = '';

    public string $dateUntil = '';

    public string $type = '';

    public string $category = '';

    public string $paymentMethod = '';

    public string $currency = Currency::DEFAULT;

    public string $search = '';

    public function mount(): void
    {
        $this->dateFrom = today()->startOfMonth()->toDateString();
        $this->dateUntil = today()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->transactionAction('income', 'შემოსავლის დამატება', 'success'),
            $this->transactionAction('expense', 'ხარჯის დამატება', 'danger'),
        ];
    }

    public function deleteManualTransaction(int $id, FinanceManager $manager): void
    {
        $manager->delete(FinanceTransaction::query()->findOrFail($id));
    }

    public function resetFilters(): void
    {
        $this->dateFrom = today()->startOfMonth()->toDateString();
        $this->dateUntil = today()->toDateString();
        $this->type = '';
        $this->category = '';
        $this->paymentMethod = '';
        $this->currency = Currency::DEFAULT;
        $this->search = '';
    }

    protected function getViewData(): array
    {
        $payments = $this->paymentQuery()->latest('payment_date')->latest('id')->limit(200)->get();
        $manual = $this->manualQuery()->latest('transaction_date')->latest('id')->limit(200)->get();
        $sales = $this->productSaleQuery()->latest('sold_at')->latest('id')->limit(200)->get();
        $entries = $payments->map(fn (Payment $payment): array => [
            'key' => 'payment-'.$payment->getKey(), 'manual_id' => null,
            'date' => $payment->payment_date, 'type' => 'income', 'category' => 'პაციენტის გადახდა',
            'source_title' => 'პაციენტის გადახდა',
            'source_secondary' => $payment->visit?->patient?->full_name,
            'visit_id' => $payment->visit_id,
            'amount' => (float) $payment->amount, 'currency' => $payment->currency,
            'methods' => $payment->splits->pluck('payment_method')->unique()->values()->all(),
            'has_time' => false,
        ])->concat($sales->map(fn (ProductSale $sale): array => [
            'key' => 'product-sale-'.$sale->getKey(), 'manual_id' => null,
            'date' => $sale->sold_at, 'type' => 'income', 'category' => 'პროდუქტის გაყიდვა',
            'source_title' => 'პროდუქტის გაყიდვა',
            'source_secondary' => $sale->items->map(fn ($item): string => $item->product->name.' ×'.$item->quantity)->join(', '),
            'visit_id' => $sale->visit_id, 'amount' => (float) $sale->total, 'currency' => $sale->currency,
            'methods' => $sale->cashboxTransactions->pluck('payment_method')->unique()->values()->all() ?: [$sale->payment_method],
            'has_time' => $sale->sold_at->format('H:i:s') !== '00:00:00',
        ]))->concat($manual->map(fn (FinanceTransaction $transaction): array => [
            'key' => 'finance-'.$transaction->getKey(), 'manual_id' => $transaction->getKey(),
            'date' => $transaction->transaction_date, 'type' => $transaction->type,
            'category' => FinanceTransaction::CATEGORIES[$transaction->category] ?? $transaction->category,
            'source_title' => $transaction->description ?: '—', 'source_secondary' => null, 'visit_id' => null,
            'amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
            'methods' => [$transaction->payment_method], 'has_time' => $transaction->transaction_date->format('H:i:s') !== '00:00:00',
        ]))->sortByDesc(fn (array $entry): string => $entry['date']->format('Y-m-d H:i:s').$entry['key'])->values();

        $income = (float) $this->paymentQuery()->sum('amount')
            + (float) $this->productSaleQuery()->sum('total')
            + (float) $this->manualQuery()->where('type', 'income')->sum('amount');
        $expense = (float) $this->manualQuery()->where('type', 'expense')->sum('amount');
        $incomeByMethod = $this->methodBreakdown('income');
        $expenseByMethod = $this->methodBreakdown('expense');

        return [
            'entries' => $entries,
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'result' => round($income - $expense, 2),
            'incomeByMethod' => $incomeByMethod,
            'expenseByMethod' => $expenseByMethod,
            'typeOptions' => FinanceTransaction::TYPES,
            'categoryOptions' => ['patient_payment' => 'პაციენტის გადახდა', 'product_sale' => 'პროდუქტის გაყიდვა'] + FinanceTransaction::CATEGORIES,
            'methodOptions' => PaymentMethod::options(),
            'currencyOptions' => Currency::OPTIONS,
        ];
    }

    /** @return array<string, float> */
    private function methodBreakdown(string $type): array
    {
        $totals = collect(PaymentMethod::cases())
            ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => 0.0]);

        if ($type === 'income') {
            $paymentTotals = PaymentSplit::query()
                ->join('payments', 'payments.id', '=', 'payment_splits.payment_id')
                ->whereIn('payment_splits.payment_id', $this->paymentQuery()->select('payments.id'))
                ->selectRaw('payment_splits.payment_method, SUM(payment_splits.amount * CASE WHEN payment_splits.currency = payments.currency THEN 1 ELSE COALESCE(payment_splits.exchange_rate, 0) END) AS total')
                ->groupBy('payment_splits.payment_method')
                ->pluck('total', 'payment_method');

            foreach ($paymentTotals as $method => $amount) {
                $totals[PaymentMethod::normalize($method)] += (float) $amount;
            }

            $saleIds = $this->productSaleQuery()->select('product_sales.id');
            $saleTotals = CashboxTransaction::query()
                ->whereIn('product_sale_id', $saleIds)->where('type', 'product_sale')
                ->selectRaw('payment_method, SUM(amount) AS total')->groupBy('payment_method')
                ->pluck('total', 'payment_method');
            $unpostedSaleTotals = $this->productSaleQuery()->whereDoesntHave('cashboxTransactions')
                ->selectRaw('payment_method, SUM(total) AS total')->groupBy('payment_method')->pluck('total', 'payment_method');
            foreach ($unpostedSaleTotals as $method => $amount) {
                $saleTotals[$method] = (float) ($saleTotals[$method] ?? 0) + (float) $amount;
            }
            foreach ($saleTotals as $method => $amount) {
                $totals[PaymentMethod::normalize($method)] += (float) $amount;
            }
        }

        $manualTotals = $this->manualQuery()->where('type', $type)
            ->selectRaw('payment_method, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        foreach ($manualTotals as $method => $amount) {
            $totals[PaymentMethod::normalize($method)] += (float) $amount;
        }

        return $totals->map(fn (float $amount): float => round($amount, 2))->all();
    }

    private function paymentQuery(): Builder
    {
        return Payment::query()->with(['visit.patient', 'splits'])
            ->whereDate('payment_date', '>=', $this->dateFrom)
            ->whereDate('payment_date', '<=', $this->dateUntil)
            ->where('currency', $this->currency)
            ->when($this->type === 'expense', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled($this->category) && $this->category !== 'patient_payment', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when(filled($this->paymentMethod), fn (Builder $query): Builder => $query
                ->whereHas('splits', fn (Builder $query): Builder => $query->where('payment_method', $this->paymentMethod)))
            ->when(filled(trim($this->search)), function (Builder $query): Builder {
                $search = '%'.mb_strtolower(trim($this->search)).'%';

                return $query->whereHas('visit.patient', fn (Builder $query): Builder => $query
                    ->whereRaw('LOWER(first_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$search]));
            });
    }

    private function manualQuery(): Builder
    {
        return FinanceTransaction::query()
            ->whereBetween('transaction_date', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ])
            ->where('currency', $this->currency)
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

    private function productSaleQuery(): Builder
    {
        return ProductSale::query()->with(['patient', 'items.product', 'cashboxTransactions'])
            ->whereBetween('sold_at', [
                Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
                Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
            ])
            ->where('currency', $this->currency)
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

    private function transactionAction(string $type, string $label, string $color): Action
    {
        return Action::make('add_'.$type)->label($label)->color($color)->size('sm')
            ->schema([
                DateTimePicker::make('transaction_date')->label('თარიღი / დრო')->timezone(config('app.timezone'))->default(now())->required(),
                Select::make('category')->label('კატეგორია')->options(FinanceTransaction::CATEGORIES)->searchable()->required(),
                TextInput::make('description')->label('აღწერა / წყარო')->maxLength(255),
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)->required(),
                Select::make('currency')->label('ვალუტა')->options(Currency::OPTIONS)->default(Currency::DEFAULT)->required(),
                Select::make('payment_method')->label('მეთოდი')->options(PaymentMethod::options())->default('cash')->live()->required(),
                Select::make('cash_source')->label('ნაღდი თანხის წყარო')->options(FinanceTransaction::CASH_SOURCES)
                    ->default('current_cashier')->visible(fn (Get $get): bool => $get('payment_method') === 'cash')
                    ->required(fn (Get $get): bool => $get('payment_method') === 'cash'),
                Textarea::make('note')->label('შენიშვნა')->rows(2),
            ])
            ->action(fn (array $data, FinanceManager $manager) => $manager->create([...$data, 'type' => $type]));
    }
}
