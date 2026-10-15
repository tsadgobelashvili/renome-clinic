<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Finance;
use App\Models\CashboxTransaction;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo('2026-10-14 12:00:00');
    $this->manager = app(CashboxManager::class);
    $previous = $this->manager->today();
    $previous->update(['opening_balance' => 2000]);
    $this->manager->close($previous, 2000, 0);
    $this->travelTo('2026-10-15 12:00:00');
    $this->day = $this->manager->today();
    $this->manager->addOpeningBalance($this->day, 400);
    $this->day->refresh();
    $this->ordinary = collect([
        ['type' => 'other_income', 'payment_method' => 'cash', 'amount' => 100, 'description' => 'Ordinary cash receipt'],
        ['type' => 'expense', 'payment_method' => 'cash', 'amount' => 30, 'description' => 'Ordinary drawer expense'],
        ['type' => 'other_income', 'payment_method' => 'card', 'amount' => 75, 'description' => 'Ordinary card receipt'],
    ])->map(fn ($data) => $this->day->transactions()->create([...$data, 'currency' => 'GEL', 'transaction_date' => now()]));
    $this->ledger = app(AccountingLedger::class);
});

function existingIrrigatorProjectionRecord(int $dayId, int $amount, ?string $source, string $note): CashboxTransaction
{
    // Represents a record that already existed before projection changes: no service,
    // migration or correction is invoked when deciding its Cashier visibility.
    $id = DB::table('cashbox_transactions')->insertGetId([
        'cashbox_day_id' => $dayId, 'type' => 'irrigator_withdrawal', 'amount' => $amount,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_usage_source' => $source,
        'cash_usage_key' => (string) Str::uuid(), 'transaction_date' => now(), 'created_by' => auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
        'description' => $note.' · '.($source === 'withdrawn_cash' ? 'Accumulated cash' : 'Cash')
            .' -'.$amount.'.00 ₾ · Card +'.$amount.'.00 ₾ · Net 0.00 ₾',
    ]);

    return CashboxTransaction::findOrFail($id);
}

test('current and legacy current irrigator movements remain in the modal current table and closed history', function (?string $source) {
    $pnl = $this->ledger->pnlTotals(null, null)->toArray();
    $record = existingIrrigatorProjectionRecord($this->day->id, 200, $source, 'Current irrigator record');
    $before = $record->getAttributes();
    Livewire::test(Dashboard::class)->mountAction('cashboxOverview')->assertMountedActionModalSee('Current irrigator record');
    Livewire::test(Cashbox::class)->assertCanSeeTableRecords($this->ordinary->push($record))
        ->assertViewHas('summary', fn ($summary) => $summary['expected'] === 270.0 && $summary['cardAdjustmentByCurrency']['GEL'] === 200.0);
    $this->manager->close($this->day, 270, 100);
    $this->travelTo('2026-10-16 12:00:00');
    Livewire::test(Cashbox::class)->assertViewHas('history', fn ($history) => $history->firstWhere('day.id', $this->day->id)['transactions']->pluck('transaction.id')->contains($record->id));
    expect($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and($record->fresh()->getAttributes())->toEqual($before);
})->with([null, 'current_cashier']);

test('existing accumulated irrigator records disappear only from Cashier without modifying their accounting or identity', function (int $amount) {
    $summary = $this->day->summary();
    $pnl = $this->ledger->pnlTotals(null, null)->toArray();
    $held = $this->manager->availableCashForOpening($this->day)['GEL'];
    $record = existingIrrigatorProjectionRecord($this->day->id, $amount, 'withdrawn_cash', 'Accumulated irrigator record '.$amount);
    $before = $record->getAttributes();
    $allRecords = DB::table('cashbox_transactions')->orderBy('id')->get()->toArray();
    expect($this->day->summary())->toEqual($summary)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe($held - $amount)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and($this->ledger->cardAmounts(null, null, 'clinic')->where('origin', 'irrigator_withdrawal')->count())->toBe(1)
        ->and((float) $this->ledger->cardAmounts(null, null, 'clinic')->where('origin', 'irrigator_withdrawal')->sum('amount'))->toBe((float) $amount);
    Livewire::test(Dashboard::class)->assertViewHas('cardReceipts', ['GEL' => 75.0, 'USD' => 0.0])
        ->mountAction('cashboxOverview')->assertMountedActionModalDontSee('Accumulated irrigator record '.$amount)
        ->assertMountedActionModalSee(['Ordinary cash receipt', 'Ordinary drawer expense', 'Ordinary card receipt']);
    Livewire::test(Cashbox::class)->assertCanNotSeeTableRecords([$record])->assertCanSeeTableRecords($this->ordinary)
        ->assertViewHas('summary', fn ($value) => $value === $summary)
        ->assertViewHas('history', fn ($history) => ! $history->firstWhere('day.id', $this->day->id)['transactions']->pluck('transaction.id')->contains($record->id));
    Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')->assertSee('Accumulated irrigator record '.$amount)
        ->call('selectOverviewCard', 'card')->assertSee('Accumulated irrigator record '.$amount);
    expect(DB::table('cashbox_transactions')->orderBy('id')->get()->toArray())->toEqual($allRecords);
    $this->manager->close($this->day, 470, 100);
    expect((float) $this->day->fresh()->expected_closing_balance)->toBe(470.0)
        ->and($this->day->summary()['expected'])->toBe(470.0)
        ->and($this->day->summary()['difference'])->toBe(0.0);
    $this->travelTo('2026-10-16 12:00:00');
    $next = $this->manager->today();
    expect((float) $next->opening_balance)->toBe(100.0)->and($next->summary()['expected'])->toBe(100.0);
    Livewire::test(Cashbox::class)->assertViewHas('history', fn ($history) => ! $history->firstWhere('day.id', $this->day->id)['transactions']->pluck('transaction.id')->contains($record->id));
    expect($record->fresh()->getAttributes())->toEqual($before)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and($this->ledger->cardAmounts(null, null, 'clinic')->where('origin', 'irrigator_withdrawal')->count())->toBe(1);
})->with([600, 800]);
