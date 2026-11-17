<?php

namespace App\Services\Finance;

use App\Models\BankTransaction;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceEntry;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\ProductSale;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\PaymentProcessor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OriginalRecordEditor
{
    public function find(string $key, bool $israeliOnly = false, bool $lock = false): Model
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        abort_unless(preg_match('/^([a-z-]+):(\d+)$/', $key, $parts), 404);
        $class = match ($parts[1]) {
            'finance' => FinanceTransaction::class, 'payment' => Payment::class,
            'partner' => PartnerFinanceTransaction::class, 'partner-payment' => PartnerPatientPayment::class,
            'bank', 'bank-fee' => BankTransaction::class, 'cashbox', 'cashbox-expense' => CashboxTransaction::class,
            'sale' => ProductSale::class, 'payroll' => PayrollEntry::class,
            'advance-expense', 'advance-return' => EmployeeAdvanceEntry::class,
            'advance-other' => EmployeeAdvance::class, default => null,
        };
        abort_unless($class, 404);
        $query = $class::query();
        if ($class === PartnerPatientPayment::class) {
            $query->withTrashed()->where(fn ($q) => $q->whereNull('deleted_at')->orWhereNotNull('cancelled_at'));
        }
        $record = $query->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($parts[2]);
        abort_if($israeliOnly && ! ($record instanceof PartnerPatientPayment
            || ($record instanceof PartnerFinanceTransaction && $record->source === 'israeli')), 403);

        return $record;
    }

    public function reason(Model $record): ?string
    {
        if ($record instanceof PartnerPatientPayment) {
            return app(\App\Services\IsraeliPatientPaymentLifecycle::class)->protectionReason($record);
        }
        if ($record instanceof PayrollEntry || $record instanceof EmployeeAdvanceEntry || $record instanceof EmployeeAdvance) {
            return 'ხელფასის ან ავანსის ჩანაწერი დაცულია; შესწორება სრულდება შესაბამის მოდულში.';
        }
        if ($record instanceof CashboxTransaction && ($record->employee_advance_id || $record->employee_advance_key)) {
            return 'ავანსის სალაროს ჩანაწერი დაცულია; გამოიყენეთ ავანსის მოდული.';
        }
        if ($record instanceof CashboxTransaction && $record->cash_transfer_id) {
            return 'შიდა გადატანის დაკავშირებული ჩანაწერი ცალკე არ რედაქტირდება.';
        }
        if (! ($record instanceof FinanceTransaction || $record instanceof PartnerFinanceTransaction
            || $record instanceof Payment || $record instanceof PartnerPatientPayment)) {
            return 'ჩანაწერი იმართება თავდაპირველ მოდულში; Finance-დან შეცვლა დაუშვებელია.';
        }
        if ($record->purchase_id) {
            return 'RS-ის გადახდა დაცულია. ცვლილებისთვის გამოიყენეთ RS დოკუმენტის გადახდის გაუქმება.';
        }
        foreach (['salary_payout_allocation_id', 'salary_settlement_id', 'employee_salary_settlement_id', 'lab_salary_settlement_id',
            'payroll_entry_id', 'reversal_of_finance_transaction_id', 'finance_transaction_id', 'employee_advance_id', 'employee_advance_key'] as $field) {
            if ($record->{$field}) {
                return 'ხელფასის, ავანსის ან დაკავშირებული მოძრაობა დაცულია. გამოიყენეთ შესაბამისი მოდულის შესწორების/გაუქმების მოქმედება.';
            }
        }
        if ($record->clinic_cash_gel !== null || in_array($record->category, ['salary', 'doctor_salary', 'lab_salary'], true)
            || in_array($record->type, ['salary_cash', 'employee_advance'], true)) {
            return 'ხელფასის ან ავანსის ჩანაწერი დაცულია; შესწორება სრულდება შესაბამის მოდულში.';
        }
        if ($record instanceof FinanceTransaction && $record->israeliCashMovement()->exists()) {
            return 'ჩანაწერს აქვს დაკავშირებული ისრაელის მოძრაობა; გამოიყენეთ თავდაპირველი ოპერაცია.';
        }
        if ($record instanceof FinanceTransaction && PartnerFinanceTransaction::query()
            ->where('transacted_at', $record->transaction_date)->where('created_by', $record->created_by)->exists()) {
            return 'ერთ ოპერაციას რამდენიმე დაკავშირებული მოძრაობა აქვს. ცალკეული ნაწილის შეცვლა დაუშვებელია.';
        }
        if ($record instanceof PartnerFinanceTransaction) {
            // USD usage can create several records with one exact operation timestamp.
            // There is no durable operation ID: do not detach one leg by guessing a link.
            if (PartnerFinanceTransaction::query()->where('source', $record->source)->where('transacted_at', $record->transacted_at)
                ->where('created_by', $record->created_by)->whereKeyNot($record->id)->exists()
                || FinanceTransaction::query()->where('transaction_date', $record->transacted_at)->where('created_by', $record->created_by)->exists()) {
                return 'ერთ ოპერაციას რამდენიმე დაკავშირებული მოძრაობა აქვს. ცალკეული ნაწილის შეცვლა დაუშვებელია.';
            }
        }
        if ($record instanceof Payment && ($record->visit()->withCancelled()->first()?->is_cancelled ?? true)) {
            return 'გაუქმებული ან დაკარგული ვიზიტის გადახდის შეცვლა დაუშვებელია.';
        }
        if ($record instanceof Payment || $record instanceof FinanceTransaction) {
            $column = $record instanceof Payment ? 'payment_id' : 'finance_transaction_id';
            if ($record instanceof Payment && $record->is_historical && CashboxTransaction::query()->where($column, $record->id)->exists()) {
                return 'ისტორიულ გადახდას აქვს სალაროს ჩანაწერი; შესწორება საჭიროა თავდაპირველ მოდულში.';
            }
            if (CashboxTransaction::query()->where($column, $record->id)->whereHas('day', fn ($q) => $q->where('status', 'closed'))->exists()) {
                return 'დახურული სალაროს მოძრაობის შეცვლა Finance-დან დაუშვებელია.';
            }
        }

        return null;
    }

    public function update(string $key, array $data, bool $israeliOnly = false): Model
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        return DB::transaction(function () use ($key, $data, $israeliOnly): Model {
            // The shared balance lock always precedes a receipt/movement lock, including this nested entry point.
            if (str_starts_with($key, 'partner-payment:') || str_starts_with($key, 'partner:')) {
                \App\Services\IsraeliFinanceBalanceLock::acquire();
            }
            $record = $this->find($key, $israeliOnly, lock: true);
            if ($reason = $this->reason($record)) {
                throw ValidationException::withMessages(['record' => $reason]);
            }
            if ($record instanceof PartnerPatientPayment) {
                return app(\App\Services\IsraeliPatientPaymentLifecycle::class)->update($record->id, $data);
            }
            $fields = $this->fields($record);
            $data = Arr::only($data, $fields);
            if ($record instanceof FinanceTransaction && ($data['cash_source'] ?? $record->cash_source) === 'israeli') {
                throw ValidationException::withMessages(['cash_source' => 'ისრაელის მოძრაობისთვის გამოიყენეთ თავდაპირველი ოპერაცია.']);
            }
            if ($record instanceof Payment || ($record instanceof FinanceTransaction
                && ($record->cash_source === 'current_cashier' || ($data['cash_source'] ?? null) === 'current_cashier'))) {
                $dateField = $record instanceof Payment ? 'payment_date' : 'transaction_date';
                $dates = [substr((string) $record->{$dateField}, 0, 10), substr((string) ($data[$dateField] ?? $record->{$dateField}), 0, 10)];
                if (! ($record instanceof Payment && $record->is_historical)
                    && CashboxDay::query()->whereIn('date', $dates)->lockForUpdate()->get()->contains('status', 'closed')) {
                    throw ValidationException::withMessages([$dateField => 'ამ თარიღის სალარო დახურულია.']);
                }
            }
            if ($record instanceof Payment) {
                if (array_key_exists('comment', $data)) {
                    $record->skipCashboxSync = true;
                    $record->update(['comment' => $data['comment']]);
                }

                return app(PaymentProcessor::class)->correct($record, $data, $data['splits']);
            }
            if ($record instanceof FinanceTransaction) {
                return app(FinanceManager::class)->update($record, $data);
            }
            if ($record instanceof PartnerFinanceTransaction) {
                return app(FinanceUsdUsageService::class)->updateMovement($record, $data);
            }
            $record->update($data); // Israeli receipt validation stays in the existing model; no treatment debt.

            return $record->refresh();
        });
    }

    public function fields(Model $record): array
    {
        return match (true) {
            $record instanceof Payment => ['payment_date', 'comment', 'splits'],
            $record instanceof PartnerPatientPayment => [...\App\Services\IsraeliPatientPaymentLifecycle::FIELDS, 'expected_version', 'operation_key', 'account'],
            $record instanceof FinanceTransaction => ['transaction_date', 'expense_direction_id', 'expense_type_id', 'description', 'amount', 'currency', 'payment_method', 'cash_source', 'note'],
            $record instanceof PartnerFinanceTransaction => array_merge(['transacted_at', 'recipient', 'from_account', 'notes'],
                $record->type === 'expense' ? ['expense_direction_id', 'expense_type_id'] : [],
                in_array($record->type, ['transfer', 'currency_exchange'], true) ? ['to_account'] : [],
                $record->type === 'currency_exchange' ? ['from_amount', 'to_amount', 'exchange_rate'] : ['amount', 'currency']),
            default => [],
        };
    }
}
