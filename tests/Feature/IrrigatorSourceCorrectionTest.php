<?php

use App\Filament\Pages\Finance;
use App\Models\CashboxTransaction;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\FinanceUsdUsageService;
use App\Services\IrrigatorSourceCorrection;
use App\Support\CashboxManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($this->owner);
    app()->setLocale('en');
    $this->travelTo('2026-10-12 12:00:00');
    $this->manager = app(CashboxManager::class);
    $previous = $this->manager->today();
    $previous->update(['opening_balance' => 2000]);
    $this->manager->close($previous, 2000, 0);
    $this->travelTo('2026-10-13 12:00:00');
    $this->day = $this->manager->today();
    $this->manager->addOpeningBalance($this->day, 1000);
    $this->day->refresh();
    $this->correction = app(IrrigatorSourceCorrection::class);
    $this->ledger = app(AccountingLedger::class);
});

function irrigatorCorrectionSnapshot(): array
{
    return collect(['payments', 'payment_splits', 'payment_audits', 'visits', 'patients',
        'finance_transactions', 'partner_finance_transactions', 'partner_patient_payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
}

test('Owner corrects legacy 600 current cash source in place with unchanged neutral Card and Pnl', function () {
    $patient = Patient::create(['first_name' => 'Source', 'last_name' => 'Correction']);
    $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => today(), 'total_price' => 100, 'currency' => 'GEL']);
    $paymentId = DB::table('payments')->insertGetId(['visit_id' => $visit->id, 'amount' => 90, 'currency' => 'GEL',
        'payment_date' => today(), 'payment_method' => 'cash', 'created_by' => $this->owner->id, 'created_at' => now()]);
    DB::table('payment_splits')->insert(['payment_id' => $paymentId, 'amount' => 90, 'currency' => 'GEL', 'payment_method' => 'cash']);
    $this->day->transactions()->create(['type' => 'patient_payment', 'payment_id' => $paymentId, 'patient_id' => $patient->id,
        'visit_id' => $visit->id, 'amount' => 90, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()]);
    DB::table('finance_transactions')->insert([
        ['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income', 'amount' => 20, 'currency' => 'GEL', 'payment_method' => 'bank', 'cash_source' => null],
        ['type' => 'expense', 'transaction_date' => now(), 'category' => 'other_expense', 'amount' => 25, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash'],
    ]);
    DB::table('partner_finance_transactions')->insert(['source' => 'israeli', 'type' => 'transfer', 'transacted_at' => now(),
        'from_account' => 'bank', 'to_account' => 'cash', 'amount' => 75, 'currency' => 'GEL']);
    $id = DB::table('cashbox_transactions')->insertGetId([
        'cashbox_day_id' => $this->day->id, 'type' => 'irrigator_withdrawal', 'amount' => 600,
        'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(),
        'created_by' => $this->owner->id, 'cash_usage_key' => (string) Str::uuid(),
        'created_at' => now(), 'updated_at' => now(),
        'description' => 'ირიგატორის თანხის აღება — 600.00 ₾ · Cash -600.00 ₾ · Card +600.00 ₾ · Net 0.00 ₾ · Original note',
    ]);
    $original = CashboxTransaction::findOrFail($id)->getAttributes();
    $pnl = $this->ledger->pnlTotals(null, null)->toArray();
    $unrelated = irrigatorCorrectionSnapshot();
    $israeli = app(FinanceUsdUsageService::class)->cashBalances('israeli');
    $physical = $this->manager->physicalCashBalances();
    $held = $this->manager->availableCashForOpening($this->day)['GEL'];
    $current = $this->day->summary()['expected'];
    Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')->assertSee(__('cash-usage.edit_source'))
        ->mountAction('editIrrigatorSource', ['id' => $id])
        ->assertFormFieldExists('source', fn ($field) => $field->getOptions() === CashboxTransaction::cashUsageSources())
        ->assertFormFieldDoesNotExist('amount')->assertFormFieldDoesNotExist('note')
        ->fillForm(['source' => 'withdrawn_cash'])->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified(__('cash-usage.source_corrected'))->assertSee('Accumulated cash -600.00 ₾');
    $corrected = CashboxTransaction::findOrFail($id);
    expect($this->day->summary()['expected'])->toBe($current + 600)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe($held - 600)
        ->and($this->manager->physicalCashBalances())->toEqual($physical)
        ->and($this->ledger->cardAmounts(null, null, 'clinic')->count())->toBe(1)
        ->and((float) $this->ledger->cardAmounts(null, null, 'clinic')->sum('amount'))->toBe(600.0)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and(irrigatorCorrectionSnapshot())->toEqual($unrelated)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toEqual($israeli)
        ->and($corrected->only(array_diff(array_keys($original), ['cash_usage_source', 'description', 'updated_at'])))->toEqual(
            array_diff_key($original, array_flip(['cash_usage_source', 'description', 'updated_at'])))
        ->and($corrected->description)->toContain('Original note', 'Card +600.00 ₾', 'Net 0.00 ₾');
    $row = app(CashOutflowReport::class)->movements('2026-10-13', '2026-10-13')->where('origin', 'irrigator_withdrawal')->sole();
    expect($row->cash_source)->toBe('accumulated_cash');
    $after = $corrected->getAttributes();
    $this->correction->correct($id, 'withdrawn_cash', 'current_cashier');
    expect($corrected->fresh()->getAttributes())->toEqual($after)->and(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(1);
});

test('accumulated to current cash applies the inverse balance correction', function () {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid(), 'withdrawn_cash');
    $this->correction->correct($record->id, 'current_cashier', 'withdrawn_cash');
    expect($this->day->summary()['expected'])->toBe(400.0)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(1000.0)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(1)
        ->and((float) $this->ledger->cardAmounts(null, null)->sum('amount'))->toBe(600.0);
});

test('insufficient new source blocks correction without changing any original attributes or balances', function (string $old, string $new, int $opening) {
    $this->day->update(['opening_balance' => $opening]);
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid(), $old);
    $attributes = $record->fresh()->getAttributes();
    $summary = $this->day->summary();
    $pool = $this->manager->availableCashForOpening($this->day);
    Log::spy();
    Livewire::test(Finance::class)->mountAction('editIrrigatorSource', ['id' => $record->id])
        ->fillForm(['source' => $new])->callMountedAction()->assertHasActionErrors(['source'])
        ->assertMountedActionModalSee(__('cash-usage.insufficient_source', [
            'source' => CashboxTransaction::cashUsageSources()[$new], 'available' => '200.00 ₾',
        ]));
    expect($record->fresh()->getAttributes())->toEqual($attributes)
        ->and($this->day->summary())->toEqual($summary)->and($this->manager->availableCashForOpening($this->day))->toEqual($pool)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(1);
    Log::shouldNotHaveReceived('info');
})->with([['current_cashier', 'withdrawn_cash', 1800], ['withdrawn_cash', 'current_cashier', 200]]);

test('database failure rolls back source and description and never schedules an audit', function () {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid());
    $attributes = $record->fresh()->getAttributes();
    $summary = $this->day->summary();
    $pool = $this->manager->availableCashForOpening($this->day);
    Log::spy();
    DB::unprepared("CREATE TRIGGER fail_source AFTER UPDATE OF cash_usage_source ON cashbox_transactions BEGIN SELECT RAISE(ABORT, 'Correction failure'); END");
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier'))->toThrow(QueryException::class);
    expect($record->fresh()->getAttributes())->toEqual($attributes)->and($this->day->summary())->toEqual($summary)
        ->and($this->manager->availableCashForOpening($this->day))->toEqual($pool)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(1);
    Log::shouldNotHaveReceived('info');
});

test('closed transaction source corrections move current available cash instead of cancelling in the retained pool', function (string $old, string $new, float $delta) {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid(), $old);
    $this->manager->close($this->day, $old === 'withdrawn_cash' ? 1000 : 400, 0);
    $closed = $this->day->fresh()->getAttributes();
    $this->travelTo('2026-10-14 12:00:00');
    $today = $this->manager->today();
    $this->manager->addOpeningBalance($today, $old === 'current_cashier' ? 600 : 1000);
    $today->refresh();
    $physical = $this->manager->physicalCashBalances()['GEL'];
    $held = $this->manager->availableCashForOpening($today)['GEL'];
    $this->correction->correct($record->id, $new, $old);
    $newHeld = $this->manager->availableCashForOpening($today)['GEL'];
    expect($newHeld)->toBe($held - $delta)->and($physical - $newHeld)->toBe($physical - $held + $delta)
        ->and($this->day->fresh()->getAttributes())->toEqual($closed)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe($physical)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(1);
    $this->correction->correct($record->id, $old, $new);
    expect($this->manager->availableCashForOpening($today)['GEL'])->toBe($held);
})->with([['current_cashier', 'withdrawn_cash', 600.0], ['withdrawn_cash', 'current_cashier', -600.0]]);

test('non owners cannot see mount or invoke the correction', function (string $role) {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid());
    $original = $record->fresh()->getAttributes();
    $this->actingAs(User::factory()->create(['role' => $role]));
    expect(fn () => $this->correction->find($record->id))->toThrow(HttpException::class);
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier'))->toThrow(HttpException::class);
    if ($role === User::ROLE_ADMINISTRATOR) {
        expect((new Finance)->editIrrigatorSourceAction()->isVisible())->toBeFalse();
        Livewire::test(Finance::class)->assertForbidden();
    }
    expect($record->fresh()->getAttributes())->toEqual($original);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('unrelated transaction types and invalid sources cannot use correction', function (string $type) {
    $record = $this->day->transactions()->create(['type' => $type, 'amount' => 600, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()]);
    $original = $record->fresh()->getAttributes();
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier'))->toThrow(HttpException::class);
    expect($record->fresh()->getAttributes())->toEqual($original);
})->with(['patient_payment', 'expense', 'cash_withdrawal', 'cash_transfer_out', 'other_income']);

test('invalid source is rejected and source audit is recorded only after commit once', function () {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid());
    expect(fn () => $this->correction->correct($record->id, 'israeli', 'current_cashier'))->toThrow(ValidationException::class);
    Log::spy();
    $afterCommit = null;
    $database = DB::getFacadeRoot();
    DB::swap(Mockery::mock($database));
    DB::shouldReceive('afterCommit')->once()->andReturnUsing(function ($callback) use (&$afterCommit) {
        $afterCommit = $callback;
    });
    $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier');
    $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier');
    Log::shouldNotHaveReceived('info');
    DB::swap($database);
    expect($afterCommit)->toBeInstanceOf(Closure::class);
    $afterCommit();
    Log::shouldHaveReceived('info')->once()->with('Irrigator cash source corrected', Mockery::on(fn ($audit) => $audit['transaction_id'] === $record->id && $audit['old_source'] === 'current_cashier'
        && $audit['new_source'] === 'withdrawn_cash' && (float) $audit['amount'] === 600.0
        && $audit['user_id'] === $this->owner->id && filled($audit['corrected_at'])));
});

test('stale source submission is rejected and inactive Owner cannot correct', function () {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid());
    $before = $record->fresh()->getAttributes();
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'withdrawn_cash'))->toThrow(ValidationException::class);
    expect($record->fresh()->getAttributes())->toEqual($before);
    $this->owner->update(['is_active' => false]);
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier'))->toThrow(HttpException::class);
});

test('source correction cannot silently cross a cash opening reset', function () {
    $record = $this->manager->reconcileIrrigator(600, null, (string) Str::uuid());
    $before = $record->fresh()->getAttributes();
    $this->travelTo('2026-10-14 12:00:00');
    DB::table('finance_opening_balances')->insert(['source' => 'cash', 'account_identifier' => '', 'effective_date' => today(), 'currency' => 'GEL', 'amount' => 3000]);
    expect(fn () => $this->correction->correct($record->id, 'withdrawn_cash', 'current_cashier'))->toThrow(ValidationException::class, __('cash-usage.before_opening'));
    expect($record->fresh()->getAttributes())->toEqual($before);
});
