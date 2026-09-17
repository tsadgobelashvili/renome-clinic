<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\LabSalarySettlement;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\PatientGroup;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Models\SalarySettlement;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceUsdUsageService
{
    public function recordIsraeliDoctorSalary(SalarySettlement $settlement): ?PartnerFinanceTransaction
    {
        if ($settlement->uses_allocations || $settlement->patient_group_slug !== PatientGroup::ISRAEL_PARTNER_SLUG) {
            return null;
        }

        if ($settlement->payment_currency === 'GEL') {
            return app(IsraeliSalaryGelFunding::class)->record($settlement);
        }

        // An undone settlement must never be reposted from a stale model instance.
        if (! SalarySettlement::query()->whereKey($settlement->getKey())->exists()) {
            return null;
        }

        $amount = Money::decimal($settlement->actual_paid_usd ?? $settlement->payment_amount);
        if (Money::minorUnits($amount) === 0) {
            return null;
        }

        $settlement->loadMissing('doctor');

        return PartnerFinanceTransaction::query()->firstOrCreate([
            'salary_settlement_id' => $settlement->getKey(),
        ], [
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
            'transacted_at' => $settlement->settled_at,
            'category' => 'doctor_salary',
            'from_account' => 'cash',
            'amount' => $amount,
            'currency' => $settlement->payment_currency,
            'recipient' => $settlement->doctor->full_name,
            'doctor_id' => $settlement->doctor_id,
            'created_by' => $settlement->created_by,
            'notes' => 'Salary settlement #'.$settlement->getKey(),
        ]);
    }

    /** @param iterable<int> $settlementIds */
    public function reverseIsraeliDoctorSalaries(iterable $settlementIds): void
    {
        $settlementIds = collect($settlementIds)->all();
        DB::transaction(function () use ($settlementIds): void {
            app(IsraeliSalaryGelFunding::class)->reverse($settlementIds);
            PartnerFinanceTransaction::query()
                ->whereIn('salary_settlement_id', $settlementIds)
                ->delete();
        });
    }

    public function recordIsraeliCashExpense(array $data): PartnerFinanceTransaction
    {
        return DB::transaction(function () use ($data): PartnerFinanceTransaction {
            $amount = Money::decimal($data['amount']);
            $currency = (string) $data['currency'];
            $available = $this->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)[$currency] ?? 0;

            if (Money::minorUnits($amount) > Money::minorUnits($available)) {
                throw ValidationException::withMessages([
                    'amount' => 'ისრაელის ხელმისაწვდომი ნაღდი თანხა არასაკმარისია.',
                ]);
            }

            return PartnerFinanceTransaction::create([
                'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
                'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
                'transacted_at' => $data['transaction_date'],
                'category' => $data['category'] ?? 'other_expense',
                'expense_direction_id' => $data['expense_direction_id'] ?? null,
                'expense_type_id' => $data['expense_type_id'] ?? null,
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'expense_subcategory_id' => $data['expense_subcategory_id'] ?? null,
                'from_account' => 'cash',
                'amount' => $amount,
                'currency' => $currency,
                'recipient' => $data['description'] ?? null,
                'notes' => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /** @return array{exchange: PartnerFinanceTransaction|null, movement: PartnerFinanceTransaction|null} */
    public function recordIsraeliOperation(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $operation = (string) $data['operation_type'];
            $mode = (string) $data['payment_mode'];
            $operations = ['doctor_salary', 'lab_salary', 'bank_deposit', 'materials', 'equipment', 'owner_withdrawal', 'other'];
            if (! in_array($operation, $operations, true)) {
                throw ValidationException::withMessages(['operation_type' => 'ოპერაციის ტიპი არასწორია.']);
            }
            if (! in_array($mode, ['direct_gel', 'direct_usd', 'exchange_usd_gel'], true)) {
                throw ValidationException::withMessages(['payment_mode' => 'გადახდის რეჟიმი არასწორია.']);
            }
            $currency = $mode === 'direct_usd' ? 'USD' : 'GEL';
            $usedAmount = Money::decimal($data['actual_amount']);
            $balances = $this->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI);
            $exchange = null;

            if ($mode === 'exchange_usd_gel') {
                $usdAmount = Money::decimal($data['usd_amount']);
                $rate = Money::decimal($data['exchange_rate']);
                $receivedGel = self::calculateReceivedGel($usdAmount, $rate);

                if (Money::minorUnits($usdAmount) > Money::minorUnits($balances['USD'])) {
                    throw ValidationException::withMessages(['usd_amount' => 'ხელმისაწვდომი USD თანხა არასაკმარისია.']);
                }
                if (Money::minorUnits($usedAmount) > Money::minorUnits($receivedGel)) {
                    throw ValidationException::withMessages(['actual_amount' => 'გამოსაყენებელი GEL მიღებულ GEL თანხას აჭარბებს.']);
                }

                $exchange = PartnerFinanceTransaction::create([
                    'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
                    'type' => PartnerFinanceTransaction::TYPE_EXCHANGE,
                    'transacted_at' => $data['transacted_at'],
                    'from_account' => 'cash', 'to_account' => 'cash',
                    'from_currency' => 'USD', 'from_amount' => $usdAmount,
                    'to_currency' => 'GEL', 'to_amount' => $receivedGel,
                    'exchange_rate' => $rate, 'notes' => $data['notes'] ?? null,
                ]);
            } elseif (Money::minorUnits($usedAmount) > Money::minorUnits($balances[$currency])) {
                throw ValidationException::withMessages([
                    'actual_amount' => "ხელმისაწვდომი {$currency} თანხა არასაკმარისია.",
                ]);
            }

            $movement = $this->createIsraeliOperationMovement($operation, $currency, $usedAmount, $data);

            return compact('exchange', 'movement');
        });
    }

    public static function calculateReceivedGel(mixed $usdAmount, mixed $rate): float
    {
        return round(Money::decimal($usdAmount) * Money::decimal($rate), 2);
    }

    /** @param array<string, mixed> $data */
    public function record(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $source = $data['source'];
            $usageType = $data['usage_type'];
            $usdAmount = Money::decimal($data['usd_amount']);
            $receivedGel = Money::decimal($data['received_gel_amount'] ?? 0);
            $expenses = $usageType === 'exchange_and_spend' ? ($data['expenses'] ?? []) : [];
            $balances = $this->cashBalances($source);

            if (Money::minorUnits($usdAmount) > Money::minorUnits($balances['USD'])) {
                throw ValidationException::withMessages(['usd_amount' => 'ხელმისაწვდომი USD თანხა არასაკმარისია.']);
            }

            if ($usageType === 'exchange_and_spend'
                && Money::minorUnits(collect($expenses)->sum('amount')) > Money::minorUnits($receivedGel)) {
                throw ValidationException::withMessages([
                    'expenses' => 'ხარჯების ჯამი მიღებულ GEL თანხას ვერ გადააჭარბებს.',
                ]);
            }

            if ($usageType === 'exchange_and_spend'
                && Money::minorUnits(collect($expenses)->sum('amount')) > Money::minorUnits($balances['GEL'] + $receivedGel)) {
                throw ValidationException::withMessages(['expenses' => 'ხელმისაწვდომი GEL თანხა არასაკმარისია.']);
            }

            if (in_array($usageType, ['exchange_only', 'exchange_and_spend'], true)) {
                PartnerFinanceTransaction::create([
                    'source' => $source,
                    'type' => PartnerFinanceTransaction::TYPE_EXCHANGE,
                    'transacted_at' => $data['transacted_at'],
                    'from_account' => 'cash', 'to_account' => 'cash',
                    'from_currency' => 'USD', 'from_amount' => $usdAmount,
                    'to_currency' => 'GEL', 'to_amount' => $receivedGel,
                    'exchange_rate' => $data['exchange_rate'], 'notes' => $data['notes'] ?? null,
                ]);
            }

            if ($usageType === 'direct_usd_expense') {
                $expenses = [[
                    'expense_direction_id' => $data['expense_direction_id'] ?? null,
                    'expense_type_id' => $data['expense_type_id'] ?? null,
                    'expense_category_id' => $data['expense_category_id'] ?? null,
                    'expense_subcategory_id' => $data['expense_subcategory_id'] ?? null,
                    'category' => $data['expense_category'] ?? 'other_expense', 'recipient' => $data['recipient'],
                    'amount' => $usdAmount, 'notes' => $data['notes'] ?? null,
                    'lab_salary_settlement_id' => $data['lab_salary_settlement_id'] ?? null,
                ]];
            }

            foreach ($expenses as $expense) {
                $expense = $this->resolveLabSalaryExpense($expense);
                $currency = $usageType === 'direct_usd_expense' ? 'USD' : 'GEL';
                if ($source === PartnerFinanceTransaction::SOURCE_ISRAELI) {
                    PartnerFinanceTransaction::create([
                        'source' => $source, 'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
                        'expense_direction_id' => $expense['expense_direction_id'] ?? null, 'expense_type_id' => $expense['expense_type_id'] ?? null,
                        'transacted_at' => $data['transacted_at'], 'expense_category_id' => $expense['expense_category_id'] ?? null,
                        'expense_subcategory_id' => $expense['expense_subcategory_id'] ?? null,
                        'category' => $expense['category'] ?? 'other_expense',
                        'from_account' => 'cash', 'amount' => $expense['amount'], 'currency' => $currency,
                        'recipient' => $expense['recipient'] ?? null, 'notes' => $expense['notes'] ?? null,
                        'lab_salary_settlement_id' => $expense['lab_salary_settlement_id'] ?? null,
                    ]);
                } else {
                    app(FinanceManager::class)->create([
                        'type' => 'expense', 'transaction_date' => $data['transacted_at'],
                        'expense_direction_id' => $expense['expense_direction_id'] ?? null,
                        'expense_type_id' => $expense['expense_type_id'] ?? null,
                        'expense_category_id' => $expense['expense_category_id'] ?? null,
                        'expense_subcategory_id' => $expense['expense_subcategory_id'] ?? null,
                        'category' => $expense['category'] ?? 'other_expense', 'description' => $expense['recipient'] ?? null,
                        'amount' => $expense['amount'], 'currency' => $currency,
                        'payment_method' => 'bank_transfer', 'note' => $expense['notes'] ?? null,
                    ]);
                }
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function transfer(array $data): PartnerFinanceTransaction
    {
        return DB::transaction(function () use ($data): PartnerFinanceTransaction {
            $source = $data['source'];
            $currency = $data['currency'];
            $amount = Money::decimal($data['amount']);

            if (! array_key_exists($data['category'], PartnerFinanceTransaction::TRANSFER_CATEGORIES)) {
                throw ValidationException::withMessages(['category' => 'გადატანის ტიპი არასწორია.']);
            }
            if (Money::minorUnits($amount) > Money::minorUnits($this->cashBalances($source)[$currency])) {
                throw ValidationException::withMessages(['amount' => 'ხელმისაწვდომი თანხა არასაკმარისია.']);
            }

            return PartnerFinanceTransaction::create([
                'source' => $source, 'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
                'transacted_at' => $data['transacted_at'], 'from_account' => 'cash', 'to_account' => 'bank',
                'amount' => $amount, 'currency' => $currency, 'category' => $data['category'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /** @return array{GEL: float, USD: float} */
    public function balances(string $source): array
    {
        $balances = ['GEL' => 0.0, 'USD' => 0.0];
        if ($source === PartnerFinanceTransaction::SOURCE_ISRAELI) {
            $payments = PartnerPatientPayment::query()->select('currency')
                ->selectRaw('SUM(amount) AS total')->whereIn('currency', ['GEL', 'USD'])
                ->groupBy('currency')->pluck('total', 'currency');
            foreach ($balances as $currency => $amount) {
                $balances[$currency] = (float) ($payments[$currency] ?? 0);
            }
        } else {
            $payments = PaymentSplit::query()->select('currency')->selectRaw('SUM(amount) AS total')
                ->whereIn('currency', ['GEL', 'USD'])->groupBy('currency')->pluck('total', 'currency');
            $sales = ProductSale::query()->select('currency')->selectRaw('SUM(total) AS total')
                ->whereIn('currency', ['GEL', 'USD'])->groupBy('currency')->pluck('total', 'currency');
            $finance = FinanceTransaction::query()->select(['type', 'currency'])
                ->selectRaw('SUM(amount) AS total, SUM(israeli_cash_gel) AS israeli_cash_total')
                ->whereIn('type', ['income', 'expense'])->groupBy('type', 'currency')->toBase()->get();
            foreach ($balances as $currency => $amount) {
                $income = $finance->first(fn ($row) => $row->currency === $currency && $row->type === 'income');
                $expense = $finance->first(fn ($row) => $row->currency === $currency && $row->type === 'expense');
                $balances[$currency] = (float) ($payments[$currency] ?? 0) + (float) ($sales[$currency] ?? 0)
                    + (float) ($income->total ?? 0) - (float) ($expense->total ?? 0);
            }
            if ($source === PartnerFinanceTransaction::SOURCE_CLINIC) {
                // This funding correction remains GEL regardless of the expense currency.
                foreach ($finance as $row) {
                    $balances['GEL'] += ($row->type === 'expense' ? 1 : -1) * (float) $row->israeli_cash_total;
                }
            }
        }

        return $this->applyMovementTotals($balances, $source, cashOnly: false);
    }

    /** @return array{GEL: float, USD: float} */
    public function cashBalances(string $source): array
    {
        $cutover = $source === PartnerFinanceTransaction::SOURCE_CLINIC ? app(CashboxManager::class)->cashCutoverDate() : null;
        if ($source === PartnerFinanceTransaction::SOURCE_CLINIC) {
            $balances = app(CashboxManager::class)->physicalCashBalances();
        } else {
            $payments = PartnerPatientPayment::query()->select('currency')->selectRaw('SUM(amount) AS total')
                ->where('payment_method', 'cash')->whereIn('currency', ['GEL', 'USD'])
                ->groupBy('currency')->pluck('total', 'currency');
            $balances = ['GEL' => (float) ($payments['GEL'] ?? 0), 'USD' => (float) ($payments['USD'] ?? 0)];
        }

        return $this->applyMovementTotals($balances, $source, cashOnly: true, cutover: $cutover);
    }

    /**
     * Aggregate movements in SQL, retaining each account/currency leg separately.
     * Only bounded grouped totals are hydrated, never transaction history.
     *
     * @param  array<string, float>  $balances
     * @return array<string, float>
     */
    private function applyMovementTotals(array $balances, string $source, bool $cashOnly, ?string $cutover = null): array
    {
        $includeExpenses = $cashOnly
            ? $source !== PartnerFinanceTransaction::SOURCE_CLINIC
            : $source === PartnerFinanceTransaction::SOURCE_ISRAELI;
        $columns = ['type', 'currency', 'from_currency', 'to_currency', 'from_account', 'to_account'];
        $movements = PartnerFinanceTransaction::query()->select($columns)
            ->selectRaw('SUM(amount) AS amount_total, SUM(from_amount) AS from_total, SUM(to_amount) AS to_total')
            ->where(function ($query) use ($source, $cutover, $includeExpenses, $cashOnly): void {
                $query->where(function ($query) use ($source, $cutover): void {
                    $query->where('source', $source)
                        ->whereIn('type', [PartnerFinanceTransaction::TYPE_EXCHANGE, PartnerFinanceTransaction::TYPE_TRANSFER,
                            PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL, PartnerFinanceTransaction::TYPE_SALARY_CASH])
                        ->when($cutover, fn ($query) => $query->where('transacted_at', '>=', $cutover));
                });
                if ($includeExpenses) {
                    $query->orWhere(fn ($query) => $query->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                        ->whereIn('currency', ['GEL', 'USD'])->when($cashOnly, fn ($query) => $query->where('from_account', 'cash')));
                }
            })
            ->groupBy($columns)->toBase()->get();

        foreach ($movements as $movement) {
            $amount = (float) $movement->amount_total;
            switch ($movement->type) {
                case PartnerFinanceTransaction::TYPE_EXPENSE:
                    $balances[$movement->currency] -= $amount;
                    break;
                case PartnerFinanceTransaction::TYPE_EXCHANGE:
                    if (! $cashOnly || $movement->from_account === 'cash') {
                        $balances[$movement->from_currency] -= (float) $movement->from_total;
                    }
                    if (! $cashOnly || $movement->to_account === 'cash') {
                        $balances[$movement->to_currency] += (float) $movement->to_total;
                    }
                    break;
                case PartnerFinanceTransaction::TYPE_TRANSFER:
                    if (! $cashOnly || $movement->from_account === 'cash') {
                        $balances[$movement->currency] -= $amount;
                    }
                    if ($cashOnly && $movement->to_account === 'cash') {
                        $balances[$movement->currency] += $amount;
                    }
                    break;
                case PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL:
                    if (! $cashOnly || $movement->from_account === 'cash') {
                        $balances[$movement->currency] -= $amount;
                    }
                    break;
                case PartnerFinanceTransaction::TYPE_SALARY_CASH:
                    if ($source === PartnerFinanceTransaction::SOURCE_ISRAELI) {
                        // Salary cash has always adjusted GEL, not the row's currency.
                        if ($movement->to_account === 'cash') {
                            $balances['GEL'] += $amount;
                        }
                        if ($movement->from_account === 'cash') {
                            $balances['GEL'] -= $amount;
                        }
                    }
                    break;
            }
        }

        return collect($balances)->map(fn (float $amount): float => round($amount, 2))->all();
    }

    /** @param array<string, mixed> $expense
     * @return array<string, mixed>
     */
    private function resolveLabSalaryExpense(array $expense): array
    {
        if (blank($expense['lab_salary_settlement_id'] ?? null)) {
            return $expense;
        }

        $settlement = LabSalarySettlement::query()->with('technician')->findOrFail($expense['lab_salary_settlement_id']);
        if ($settlement->actual_paid_gel !== null || $settlement->financeExpense()->exists()) {
            throw ValidationException::withMessages(['expenses' => 'This laboratory salary settlement is already paid.']);
        }
        if (Money::minorUnits($expense['amount']) !== Money::minorUnits($settlement->salary_total)) {
            throw ValidationException::withMessages([
                'expenses' => 'ლაბის ხელფასის ხარჯი არჩეული settlement-ის თანხას უნდა ემთხვეოდეს.',
            ]);
        }

        $expense['recipient'] = $settlement->technician->name;

        return $expense;
    }

    /** @param array<string, mixed> $data */
    private function createIsraeliOperationMovement(string $operation, string $currency, float $amount, array $data): ?PartnerFinanceTransaction
    {
        if (Money::minorUnits($amount) === 0) {
            return null;
        }

        $common = [
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'transacted_at' => $data['transacted_at'],
            'from_account' => 'cash',
            'amount' => $amount,
            'currency' => $currency,
            'recipient' => $data['recipient'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($operation === 'bank_deposit') {
            return PartnerFinanceTransaction::create([
                ...$common, 'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
                'to_account' => 'bank', 'category' => 'bank_deposit',
            ]);
        }

        if ($operation === 'owner_withdrawal') {
            return PartnerFinanceTransaction::create([
                ...$common, 'type' => PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
                'category' => 'owner_withdrawal',
            ]);
        }

        $category = match ($operation) {
            'doctor_salary' => 'doctor_salary',
            'lab_salary' => 'lab_salary',
            'materials' => 'materials',
            'equipment' => 'equipment',
            default => 'other_expense',
        };

        return PartnerFinanceTransaction::create([
            ...$common, 'type' => PartnerFinanceTransaction::TYPE_EXPENSE, 'category' => $category,
            'expense_direction_id' => $data['expense_direction_id'] ?? null,
            'expense_type_id' => $data['expense_type_id'] ?? null,
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'expense_subcategory_id' => $data['expense_subcategory_id'] ?? null,
        ]);
    }
}
