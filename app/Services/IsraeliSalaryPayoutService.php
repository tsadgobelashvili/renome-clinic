<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IsraeliSalaryPayoutService
{
    public static function equivalent(array $row): float
    {
        return round(max(0, (float) ($row['amount'] ?? 0)) * (($row['currency'] ?? 'GEL') === 'USD' ? max(0, (float) ($row['exchange_rate'] ?? 0)) : 1), 2);
    }

    public function finalizeAndPay(int $doctorId, string $from, string $until, array $workIds, array $rows, string $key, User $actor, bool $deductExternal = false): SalaryPayout
    {
        $rows = $this->validate($rows, $key, $actor, $deductExternal);
        $workIds = array_values(array_unique(array_map('intval', $workIds)));
        sort($workIds);
        $hash = $this->hash(['new', $doctorId, $from, $until, $workIds, $rows, ...($deductExternal ? [true] : [])]);

        return DB::transaction(function () use ($doctorId, $from, $until, $workIds, $rows, $key, $actor, $hash, $deductExternal) {
            Doctor::query()->where(fn ($q) => $q->whereKey($doctorId)->orWhereNotNull('owner_split_key'))->orderBy('id')->lockForUpdate()->get();
            if ($existing = $this->replay($key, $hash)) {
                return $existing;
            }
            $doctor = Doctor::findOrFail($doctorId);
            $settlements = app(SalarySettlementService::class)->settle($doctorId, $from, $until,
                (float) $doctor->compensation_percentage, $actor->id,
                patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, selectedLabWorkIds: $workIds, israeliLabOnly: true, deferIsraeliPayment: true);
            if (count($settlements) !== 1 || $settlements[0]->currency !== 'GEL') {
                throw ValidationException::withMessages(['allocations' => __('salary-payout.gel_basis')]);
            }

            return $this->post($settlements[0], $rows, $key, $hash, $actor, $deductExternal);
        });
    }

    public function payRemaining(int $settlementId, array $rows, string $key, User $actor, bool $deductExternal = false): SalaryPayout
    {
        $rows = $this->validate($rows, $key, $actor, $deductExternal);
        $hash = $this->hash(['existing', $settlementId, $rows, ...($deductExternal ? [true] : [])]);

        return DB::transaction(function () use ($settlementId, $rows, $key, $hash, $actor, $deductExternal) {
            $doctorId = SalarySettlement::whereKey($settlementId)->value('doctor_id');
            Doctor::query()->whereKey($doctorId)->lockForUpdate()->firstOrFail();
            $settlement = SalarySettlement::query()->lockForUpdate()->findOrFail($settlementId);
            if ($existing = $this->replay($key, $hash)) {
                return $existing;
            }

            return $this->post($settlement, $rows, $key, $hash, $actor, $deductExternal);
        });
    }

    public function remaining(SalarySettlement $settlement): float
    {
        return max(0, round((float) $settlement->salary_total - (float) $settlement->payouts()->sum('total_gel'), 2));
    }

    private function post(SalarySettlement $settlement, array $rows, string $key, string $hash, User $actor, bool $deductExternal): SalaryPayout
    {
        if (! $settlement->uses_allocations || $settlement->status !== 'confirmed'
            || $settlement->patient_group_slug !== PatientGroup::ISRAEL_PARTNER_SLUG || $settlement->currency !== 'GEL') {
            throw ValidationException::withMessages(['allocations' => __('salary-payout.unavailable')]);
        }
        $deduction = $deductExternal ? min($this->remaining($settlement), app(ExternalLabDebt::class)->balance($settlement->doctor_id)) : 0;
        $total = round(array_sum(array_column($rows, 'gel_equivalent')) + $deduction, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['allocations' => __('salary-payout.positive')]);
        }
        if (Money::minorUnits($total) > Money::minorUnits($this->remaining($settlement))) {
            throw ValidationException::withMessages(['allocations' => __('salary-payout.overallocated')]);
        }
        // Same shared locks and balance services as existing salary cash funding.
        PatientGroup::query()->whereKey(PatientGroup::israelPartnerId())->lockForUpdate()->firstOrFail();
        $method = $settlement->israeli_payment_method ?? 'cash';
        validator(['method' => $method], ['method' => 'required|in:cash,bank_transfer'])->validate();
        if ($method === 'cash' && collect($rows)->contains('source', 'clinic')) {
            $day = app(CashboxManager::class)->today();
            $day->newQuery()->whereKey($day->id)->lockForUpdate()->firstOrFail();
        }
        // Bank payouts are recorded against the bank ledger, never a cash balance.
        $required = collect($method === 'cash' ? $rows : [])->groupBy(fn ($row) => $row['source'].'|'.$row['currency'])->map(fn ($rows) => round($rows->sum('amount'), 2));
        $balances = [];
        foreach ($required as $bucket => $amount) {
            [$source, $currency] = explode('|', $bucket);
            $balances[$source] ??= app(FinanceUsdUsageService::class)->cashBalances($source);
            if (Money::minorUnits($amount) > Money::minorUnits($balances[$source][$currency] ?? 0)) {
                throw ValidationException::withMessages(['allocations' => __('salary-payout.insufficient', ['source' => __('salaries.'.$source), 'currency' => $currency])]);
            }
        }
        $payout = $settlement->payouts()->create(['request_key' => $key, 'request_hash' => $hash, 'total_gel' => $total, 'external_deduction_gel' => $deduction, 'created_by' => $actor->id]);
        if ($deduction > 0) {
            app(ExternalLabDebt::class)->deduct($payout, $deduction);
        }
        foreach ($rows as $row) {
            $allocation = $payout->allocations()->create($row);
            $description = __('salary-payout.title').' — '.$settlement->doctor->full_name.' #'.$settlement->id;
            if ($row['source'] === 'clinic') {
                app(FinanceManager::class)->create(['salary_payout_allocation_id' => $allocation->id,
                    'type' => 'expense', 'category' => 'salary', 'transaction_date' => now(),
                    'amount' => $row['amount'], 'currency' => $row['currency'], 'payment_method' => $method,
                    'cash_source' => $method === 'cash' ? 'withdrawn_cash' : null, 'description' => $description, 'created_by' => $actor->id]);
            } else {
                PartnerFinanceTransaction::create(['salary_payout_allocation_id' => $allocation->id,
                    'source' => 'israeli', 'type' => 'expense', 'category' => 'doctor_salary', 'transacted_at' => now(), 'from_account' => $method === 'cash' ? 'cash' : 'bank',
                    'amount' => $row['amount'], 'currency' => $row['currency'], 'exchange_rate' => $row['exchange_rate'],
                    'recipient' => $settlement->doctor->full_name, 'doctor_id' => $settlement->doctor_id,
                    'notes' => $description, 'created_by' => $actor->id]);
            }
        }

        return $payout->load('allocations');
    }

    private function validate(array $rows, string $key, User $actor, bool $deductExternal): array
    {
        abort_unless($actor->isOwner() || $actor->isAdministrator(), 403);
        validator(['allocations' => $rows, 'request_key' => $key], [
            'request_key' => 'required|uuid', 'allocations' => ($deductExternal ? 'present|array|max:20' : 'required|array|min:1|max:20'),
            'allocations.*.source' => 'required|in:clinic,israeli', 'allocations.*.currency' => 'required|in:GEL,USD',
            'allocations.*.amount' => 'required|numeric|gt:0|max:99999999999.99|decimal:0,2',
        ])->validate();
        $result = [];
        foreach ($rows as $index => $row) {
            if ($row['currency'] === 'USD') {
                validator(['allocations' => [$index => $row]], ["allocations.$index.exchange_rate" => 'required|numeric|gt:0|max:99999999|decimal:0,6'])->validate();
            }
            $row = ['source' => $row['source'], 'currency' => $row['currency'], 'amount' => round((float) $row['amount'], 2),
                'exchange_rate' => $row['currency'] === 'USD' ? round((float) $row['exchange_rate'], 6) : null];
            $row['gel_equivalent'] = self::equivalent($row);
            if ($row['gel_equivalent'] <= 0) {
                throw ValidationException::withMessages(["allocations.$index.amount" => __('salary-payout.positive')]);
            }
            $result[] = $row;
        }

        return $result;
    }

    private function replay(string $key, string $hash): ?SalaryPayout
    {
        $existing = SalaryPayout::where('request_key', $key)->first();
        if ($existing && ! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['allocations' => __('salary-payout.changed')]);
        }

        return $existing?->load('allocations');
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
