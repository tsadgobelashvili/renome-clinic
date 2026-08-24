<?php

namespace App\Support;

use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashboxManager
{
    public function dayFor(string $date): CashboxDay
    {
        if ($day = CashboxDay::whereDate('date', $date)->first()) {
            return $day;
        }

        $previous = CashboxDay::whereDate('date', '<', $date)->latest('date')->first();

        try {
            return CashboxDay::create([
                'date' => $date,
                'opening_balance' => $previous?->status === 'closed' ? $previous->carry_forward_balance : 0,
                'opened_at' => now(),
                'status' => 'open',
            ]);
        } catch (QueryException $exception) {
            return CashboxDay::whereDate('date', $date)->firstOrFail();
        }
    }

    public function today(): CashboxDay
    {
        return $this->dayFor(today()->toDateString());
    }

    public function unresolvedPreviousDay(): ?CashboxDay
    {
        return CashboxDay::whereDate('date', '<', today())->where('status', 'open')->oldest('date')->first();
    }

    public function syncPayment(Payment $payment): void
    {
        if ($payment->trashed()) {
            CashboxTransaction::where('payment_id', $payment->getKey())->delete();

            return;
        }

        $payment->loadMissing('visit');
        $day = $this->dayFor($payment->payment_date->toDateString());

        CashboxTransaction::updateOrCreate(['payment_id' => $payment->getKey()], [
            'cashbox_day_id' => $day->getKey(),
            'type' => 'patient_payment',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => $payment->splits()->count() > 1 ? 'split' : $payment->payment_method,
            'transaction_date' => $payment->payment_date->copy()->setTimeFrom($payment->created_at ?? now()),
            'patient_id' => $payment->visit?->patient_id,
            'visit_id' => $payment->visit_id,
            'description' => $payment->comment,
            'created_by' => $payment->created_by,
        ]);
    }

    public function summary(CashboxDay $day): array
    {
        $manual = $day->transactions()->where('currency', 'GEL')->where('type', '!=', 'patient_payment');
        $paymentIds = $day->transactions()->where('type', 'patient_payment')->pluck('payment_id');
        $splitTotals = DB::table('payment_splits')->whereIn('payment_id', $paymentIds)
            ->where('currency', 'GEL')->selectRaw('payment_method, SUM(amount) total')
            ->groupBy('payment_method')->pluck('total', 'payment_method');

        $cashIncome = (float) ($splitTotals['cash'] ?? 0);
        $cardIncome = (float) ($splitTotals['card'] ?? 0);
        $cashExpenses = (float) (clone $manual)->where('type', 'expense')->where('payment_method', 'cash')->sum('amount');
        $cardExpenses = (float) (clone $manual)->where('type', 'expense')->where('payment_method', 'card')->sum('amount');
        $withdrawals = (float) (clone $manual)->where('type', 'cash_withdrawal')->sum('amount');
        $expected = round((float) $day->opening_balance + $cashIncome - $cashExpenses - $withdrawals, 2);

        return compact('cashIncome', 'cardIncome', 'cashExpenses', 'cardExpenses', 'withdrawals', 'expected') + [
            'difference' => $day->actual_closing_balance === null ? null : round(
                (float) $day->actual_closing_balance - (float) ($day->status === 'closed' ? $day->expected_closing_balance : $expected),
                2,
            ),
        ];
    }

    public function close(CashboxDay $day, float $actual, float $carry, ?string $notes = null): void
    {
        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['actual_closing_balance' => 'ეს დღე უკვე დახურულია.']);
        }
        if ($actual < 0 || $carry < 0 || $carry > $actual) {
            throw ValidationException::withMessages(['carry_forward_balance' => 'დასატოვებელი თანხა უნდა იყოს 0-დან ფაქტობრივ ნაშთამდე.']);
        }

        DB::transaction(function () use ($day, $actual, $carry, $notes): void {
            $expectedBeforeClosingWithdrawal = $this->summary($day)['expected'];
            $withdrawal = round($actual - $carry, 2);
            if ($withdrawal > 0) {
                $day->transactions()->create([
                    'type' => 'cash_withdrawal', 'amount' => $withdrawal, 'currency' => 'GEL',
                    'payment_method' => 'cash', 'transaction_date' => now(),
                    'description' => 'დღის დახურვისას სალაროდან ამოღებული ქეში',
                ]);
            }
            $summary = $this->summary($day->refresh());
            $day->update([
                'expected_closing_balance' => $expectedBeforeClosingWithdrawal,
                'actual_closing_balance' => $actual,
                'cash_withdrawal_total' => $summary['withdrawals'],
                'carry_forward_balance' => $carry,
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'notes' => $notes,
            ]);

            CashboxDay::whereDate('date', '>', $day->date)
                ->where('status', 'open')
                ->oldest('date')
                ->first()?->update(['opening_balance' => $carry]);
        });
    }
}
