<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Issues money once; confirmations consume the receivable without posting another payment. */
class EmployeeAdvanceService
{
    public function issue(array $data, User $actor): EmployeeAdvance
    {
        $this->authorize($actor);
        $data = validator($data, [
            'posting_key' => 'required|uuid', 'employee_id' => 'required|integer|exists:employees,id',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:99999999999',
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'source' => 'required|in:cashbox,accumulated_cash,bank,other',
            'is_salary_advance' => 'sometimes|boolean',
            'bank_transaction_id' => 'nullable|required_if:source,bank|integer', 'note' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($data, $actor): EmployeeAdvance {
            // Also serializes retries of a new advance before it has its own row to lock.
            Employee::lockForUpdate()->findOrFail($data['employee_id']);
            if ($existing = EmployeeAdvance::where('posting_key', $data['posting_key'])->first()) {
                if ($existing->employee_id != $data['employee_id'] || Money::minorUnits($existing->amount) !== Money::minorUnits($data['amount'])
                    || $existing->is_salary_advance !== (bool) ($data['is_salary_advance'] ?? false)
                    || $existing->source !== $data['source'] || $existing->date->toDateString() !== $data['date']) {
                    $this->fail('ეს მოთხოვნა უკვე გამოყენებულია სხვა ავანსისთვის.');
                }

                return $existing;
            }
            if ($data['source'] === 'bank') {
                $bank = BankTransaction::lockForUpdate()->findOrFail($data['bank_transaction_id']);
                if ($bank->direction !== 'outflow' || $bank->currency !== 'GEL' || $bank->operation_type === 'COM' || $bank->is_legacy
                    || Money::minorUnits($bank->amount) !== Money::minorUnits($data['amount'])
                    || $bank->transaction_date->toDateString() !== $data['date']
                    || DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->exists()
                    || EmployeeAdvance::where('bank_transaction_id', $bank->id)->exists()) {
                    $this->fail('აირჩიეთ იმავე თარიღის, თანხისა და GEL ვალუტის თავისუფალი საბანკო გასავალი.');
                }
            } else {
                $data['bank_transaction_id'] = null;
            }
            $advance = EmployeeAdvance::create([...$data, 'currency' => 'GEL', 'created_by' => $actor->id]);
            $this->cashMovement($advance, $advance->amount, $data['date'], false, $actor);

            return $advance;
        });
    }

    public function settlePurchase(int $advanceId, int $purchaseId, string $date, string $postingKey, User $actor): EmployeeAdvanceEntry
    {
        $this->authorize($actor);
        $this->validateDateAndKey($date, $postingKey);

        return DB::transaction(function () use ($advanceId, $purchaseId, $date, $postingKey, $actor): EmployeeAdvanceEntry {
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advanceId);
            $purchase = Purchase::lockForUpdate()->findOrFail($purchaseId);
            $this->assertPurchaseAdvance($advance);
            if ($existing = EmployeeAdvanceEntry::where('purchase_id', $purchaseId)->first()) {
                if ($existing->employee_advance_id !== $advance->id) {
                    $this->fail('დოკუმენტი უკვე დაკავშირებულია სხვა ავანსთან.');
                }

                return $existing;
            }
            $this->assertOpen($advance, $date);
            if ($purchase->source !== 'rs' || $purchase->cashExpense()->exists() || $purchase->bankTransactions()->exists()) {
                $this->fail('დოკუმენტი უკვე გადახდილია ან არ არის RS დოკუმენტი.');
            }
            $totals = $purchase->items()->selectRaw('SUM(line_total) AS total, MIN(line_total) AS minimum')->first();
            if (Money::minorUnits($totals->total) <= 0 || $totals->minimum < 0
                || Money::minorUnits($totals->total) !== Money::minorUnits($purchase->total_amount)) {
                $this->fail('დოკუმენტის ჯამი და პროდუქციის თანხები არ ემთხვევა.');
            }

            return $advance->entries()->create([
                'posting_key' => $postingKey, 'kind' => 'rs', 'expense_date' => $date, 'amount' => $purchase->total_amount,
                'purchase_id' => $purchase->id, 'description' => 'RS №'.($purchase->document_number ?: $purchase->id), 'created_by' => $actor->id,
            ]);
        });
    }

    public function manualExpense(int $advanceId, array $data, User $actor): EmployeeAdvanceEntry
    {
        $this->authorize($actor);
        $data = validator($data, [
            'posting_key' => 'required|uuid', 'expense_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'amount' => 'required|numeric|gt:0|decimal:0,2|max:99999999999',
            'expense_direction_id' => 'required|integer', 'expense_type_id' => 'nullable|integer',
            'description' => 'required|string|max:500', 'note' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($advanceId, $data, $actor): EmployeeAdvanceEntry {
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advanceId);
            $this->assertPurchaseAdvance($advance);
            if ($existing = EmployeeAdvanceEntry::where('posting_key', $data['posting_key'])->first()) {
                if ($existing->employee_advance_id !== $advance->id || $existing->kind !== 'manual'
                    || Money::minorUnits($existing->amount) !== Money::minorUnits($data['amount'])) {
                    $this->fail('ეს მოთხოვნა უკვე გამოყენებულია სხვა ჩანაწერისთვის.');
                }

                return $existing;
            }
            $this->assertOpen($advance, $data['expense_date']);
            app(ExpenseDimensions::class)->validate($data, required: true);

            return $advance->entries()->create([...$data, 'kind' => 'manual', 'created_by' => $actor->id]);
        });
    }

    public function returnRemaining(int $advanceId, string $date, string $postingKey, string $expectedAmount, User $actor): EmployeeAdvanceEntry
    {
        $this->authorize($actor);
        $this->validateDateAndKey($date, $postingKey);

        return DB::transaction(function () use ($advanceId, $date, $postingKey, $expectedAmount, $actor): EmployeeAdvanceEntry {
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advanceId);
            if ($existing = $advance->entries()->where('kind', 'return')->first()) {
                return $existing;
            }
            $this->assertOpen($advance, $date);
            $remaining = $advance->remaining_amount;
            if (Money::minorUnits($remaining) <= 0 || Money::minorUnits($remaining) !== Money::minorUnits($expectedAmount)) {
                $this->fail('დარჩენილი თანხა შეიცვალა. განაახლეთ გვერდი და დაადასტურეთ თავიდან.');
            }
            $entry = $advance->entries()->create([
                'posting_key' => $postingKey, 'kind' => 'return', 'expense_date' => $date, 'amount' => $remaining,
                'description' => 'ავანსის ნაშთის დაბრუნება', 'created_by' => $actor->id,
            ]);
            $this->cashMovement($advance, $remaining, $date, true, $actor);

            return $entry;
        });
    }

    private function cashMovement(EmployeeAdvance $advance, string $amount, string $date, bool $return, User $actor): void
    {
        if (! in_array($advance->source, ['cashbox', 'accumulated_cash'], true)) {
            return; // Bank movements already exist in Bank; never synthesize a second debit/credit.
        }
        $manager = app(CashboxManager::class);
        if (($cutover = $manager->cashCutoverDate()) && $date < $cutover) {
            $this->fail('თარიღი მიმდინარე ნაღდი ნაშთის საწყის თარიღამდეა.');
        }
        $day = $manager->dayFor($date);
        $day = CashboxDay::lockForUpdate()->findOrFail($day->id);
        if ($day->status === 'closed') {
            $this->fail('ამ თარიღის სალარო უკვე დახურულია.');
        }
        if (! $return) {
            $available = $advance->source === 'cashbox'
                ? $manager->summary($day)['expectedByCurrency']['GEL']
                : $manager->availableCashForOpening($day)['GEL'];
            if (Money::minorUnits($amount) > Money::minorUnits($available)
                || Money::minorUnits($amount) > Money::minorUnits(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])) {
                $this->fail('არჩეული წყაროს ხელმისაწვდომი ნაღდი თანხა არასაკმარისია.');
            }
        }
        $attributes = [
            'employee_advance_id' => $advance->id, 'employee_advance_key' => ($return ? 'return:' : 'issue:').$advance->id,
            'amount' => $amount, 'currency' => 'GEL', 'created_by' => $actor->id,
        ];
        $movementDate = $date === today()->toDateString() ? now() : $date;
        $description = ($return ? 'ავანსის დაბრუნება' : 'თანამშრომლის ავანსი').' #'.$advance->id.' · '.$advance->employee->full_name;
        if ($advance->source === 'cashbox') {
            CashboxTransaction::create([...$attributes, 'cashbox_day_id' => $day->id, 'type' => $return ? 'cash_transfer_in' : 'cash_withdrawal',
                'payment_method' => 'cash', 'transaction_date' => $movementDate, 'description' => $description]);
        } else {
            PartnerFinanceTransaction::create([...$attributes, 'source' => 'clinic', 'type' => PartnerFinanceTransaction::TYPE_EMPLOYEE_ADVANCE,
                'transacted_at' => $movementDate, 'from_account' => $return ? null : 'cash', 'to_account' => $return ? 'cash' : null, 'notes' => $description]);
        }
    }

    private function assertOpen(EmployeeAdvance $advance, string $date): void
    {
        if ($date < $advance->date->toDateString() || $advance->entries()->where('kind', 'return')->exists() || $advance->status === 'settled') {
            $this->fail('ავანსი დახურულია ან თარიღი ავანსის გაცემამდეა.');
        }
    }

    private function assertPurchaseAdvance(EmployeeAdvance $advance): void
    {
        if ($advance->is_salary_advance) {
            $this->fail('ხელფასის ავანსი მხოლოდ ხელფასის დაფიქსირებისას იფარება.');
        }
    }

    public function salaryAllocation(int $employeeId, string $currency, mixed $salary, bool $lock = false): array
    {
        $remaining = Money::minorUnits($salary);
        $allocation = [];
        $advances = EmployeeAdvance::query()->where('employee_id', $employeeId)->where('is_salary_advance', true)
            ->where('currency', $currency)->whereDate('date', '<=', today())
            ->whereDoesntHave('entries', fn ($q) => $q->where('kind', 'return'))
            ->withTotals()->orderBy('date')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($lock) {
            // Refresh sums after acquiring row locks: a concurrent return may have
            // committed while the SELECT waited, after its original snapshot began.
            $advances->loadSum(['entries as confirmed_total' => fn ($q) => $q->whereIn('kind', ['rs', 'manual'])], 'amount');
            $advances->loadSum(['entries as salary_total' => fn ($q) => $q->where('kind', 'salary')], 'amount');
            $advances->loadSum(['entries as returned_total' => fn ($q) => $q->where('kind', 'return')], 'amount');
        }
        foreach ($advances as $advance) {
            $amount = min($remaining, Money::minorUnits($advance->remaining_amount));
            if ($amount > 0) {
                $allocation[$advance->id] = $amount / 100;
                $remaining -= $amount;
            }
            if ($remaining <= 0) {
                break;
            }
        }

        return $allocation;
    }

    /** Called by the existing finalizer under its employee lock and transaction. */
    public function applySalary(PayrollEntry $payroll, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($payroll, $actor): void {
            Employee::whereKey($payroll->employee_id)->lockForUpdate()->firstOrFail();
            $payroll = PayrollEntry::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'draft' || EmployeeAdvanceEntry::where('payroll_entry_id', $payroll->id)->exists()) {
                $this->fail('ხელფასის ავანსის გამოყენება მხოლოდ დაფიქსირებისას შეიძლება.');
            }
            $allocation = $this->salaryAllocation($payroll->employee_id, $payroll->currency, $payroll->net_amount, true);
            $classification = app(ExpenseDimensions::class)->infer(['category' => 'salary', 'payroll_entry_id' => $payroll->id]);
            foreach ($allocation as $advanceId => $amount) {
                EmployeeAdvanceEntry::create([
                    ...$classification,
                    'posting_key' => (string) \Illuminate\Support\Str::uuid(), 'employee_advance_id' => $advanceId,
                    'payroll_entry_id' => $payroll->id, 'kind' => 'salary', 'expense_date' => today(),
                    'amount' => $amount, 'description' => 'ხელფასიდან დაქვითვა · Payroll #'.$payroll->id, 'created_by' => $actor->id,
                ]);
            }
            $payroll->update(['salary_advance_applied' => array_sum($allocation)]);
        });
        $payroll->refresh();
    }

    private function validateDateAndKey(string $date, string $postingKey): void
    {
        validator(compact('date', 'postingKey'), ['date' => 'required|date_format:Y-m-d|before_or_equal:today', 'postingKey' => 'required|uuid'])->validate();
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->canManageOwnerModules(), 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['advance' => $message]);
    }
}
