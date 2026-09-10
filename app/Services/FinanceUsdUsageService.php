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
        if ($settlement->patient_group_slug !== PatientGroup::ISRAEL_PARTNER_SLUG) {
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
        if ($source === PartnerFinanceTransaction::SOURCE_ISRAELI) {
            $balances = [
                'GEL' => (float) PartnerPatientPayment::query()->where('currency', 'GEL')->sum('amount'),
                'USD' => (float) PartnerPatientPayment::query()->where('currency', 'USD')->sum('amount'),
            ];
            foreach (['GEL', 'USD'] as $currency) {
                $balances[$currency] -= (float) PartnerFinanceTransaction::query()->israeli()
                    ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                    ->where('currency', $currency)->sum('amount');
            }
        } else {
            $balances = [];
            foreach (['GEL', 'USD'] as $currency) {
                $balances[$currency] = (float) PaymentSplit::query()->where('currency', $currency)->sum('amount')
                    + (float) ProductSale::query()->where('currency', $currency)->sum('total')
                    + (float) FinanceTransaction::query()->where('type', 'income')->where('currency', $currency)->sum('amount')
                    - (float) FinanceTransaction::query()->where('type', 'expense')->where('currency', $currency)->sum('amount');
            }
        }

        if ($source === 'clinic') {
            $balances['GEL'] += (float) FinanceTransaction::where('type', 'expense')->sum('israeli_cash_gel')
                - (float) FinanceTransaction::where('type', 'income')->sum('israeli_cash_gel');
        }

        $exchanges = PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->get();
        foreach ($exchanges as $exchange) {
            $balances[$exchange->from_currency] -= (float) $exchange->from_amount;
            $balances[$exchange->to_currency] += (float) $exchange->to_amount;
        }

        foreach (PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->get() as $transfer) {
            $balances[$transfer->currency] -= (float) $transfer->amount;
        }

        foreach (PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL)->get() as $withdrawal) {
            $balances[$withdrawal->currency] -= (float) $withdrawal->amount;
        }

        if ($source === 'israeli') {
            $balances['GEL'] += (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('to_account', 'cash')->sum('amount')
                - (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('from_account', 'cash')->sum('amount');
        }

        return collect($balances)->map(fn (float $amount): float => round($amount, 2))->all();
    }

    /** @return array{GEL: float, USD: float} */
    public function cashBalances(string $source): array
    {
        if ($source === PartnerFinanceTransaction::SOURCE_CLINIC) {
            $balances = app(CashboxManager::class)->physicalCashBalances();
        } else {
            $balances = [];
            foreach (['GEL', 'USD'] as $currency) {
                $balances[$currency] = (float) PartnerPatientPayment::query()
                    ->where('payment_method', 'cash')->where('currency', $currency)->sum('amount')
                    - (float) PartnerFinanceTransaction::query()->israeli()
                        ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                        ->where('from_account', 'cash')->where('currency', $currency)->sum('amount');
            }
        }

        foreach (PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->get() as $exchange) {
            if ($exchange->from_account === 'cash') {
                $balances[$exchange->from_currency] -= (float) $exchange->from_amount;
            }
            if ($exchange->to_account === 'cash') {
                $balances[$exchange->to_currency] += (float) $exchange->to_amount;
            }
        }

        foreach (PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->get() as $transfer) {
            if ($transfer->from_account === 'cash') {
                $balances[$transfer->currency] -= (float) $transfer->amount;
            }
            if ($transfer->to_account === 'cash') {
                $balances[$transfer->currency] += (float) $transfer->amount;
            }
        }

        foreach (PartnerFinanceTransaction::query()->where('source', $source)
            ->where('type', PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL)->get() as $withdrawal) {
            if ($withdrawal->from_account === 'cash') {
                $balances[$withdrawal->currency] -= (float) $withdrawal->amount;
            }
        }

        if ($source === 'israeli') {
            $balances['GEL'] += (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('to_account', 'cash')->sum('amount')
                - (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('from_account', 'cash')->sum('amount');
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
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'expense_subcategory_id' => $data['expense_subcategory_id'] ?? null,
        ]);
    }
}
