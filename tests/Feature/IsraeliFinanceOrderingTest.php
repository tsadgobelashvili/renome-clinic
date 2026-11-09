<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PartnerFinanceSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.timezone' => 'Asia/Tbilisi']);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 16:05:00', 'Asia/Tbilisi'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create([
        'first_name' => 'Ordering', 'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $this->payment = fn (array $data = []) => $this->patient->partnerPayments()->create(array_replace([
        'paid_at' => '2026-11-09', 'amount' => 5000, 'currency' => 'USD', 'payment_method' => 'cash',
    ], $data));
    $this->movement = fn (array $data = []) => PartnerFinanceTransaction::create(array_replace([
        'type' => 'expense', 'transacted_at' => '2026-11-09 16:05:00',
        'category' => 'materials', 'from_account' => 'cash', 'amount' => 200, 'currency' => 'USD',
    ], $data));
    $this->entries = fn (array $keys) => collect($keys)->map(fn ($key) => PartnerFinanceEntry::findOrFail($key));
});

test('later patient payments precede an expense and each other by creation time', function () {
    $expense = ($this->movement)();
    $this->travelTo(now()->setTime(16, 10));
    $first = ($this->payment)();
    $this->travelTo(now()->setTime(16, 15));
    $last = ($this->payment)(['amount' => 3000]);

    $page = Livewire::test(ListPartnerFinance::class)
        ->assertCanSeeTableRecords(($this->entries)(["payment-$last->id", "payment-$first->id", "transaction-$expense->id"]), inOrder: true);
    $column = $page->instance()->getTable()->getColumn('transacted_at');
    foreach ($page->instance()->getTableRecords() as $entry) {
        expect($column->formatState($entry->transacted_at))->toBe('09.11.2026');
        expect($entry->created_at)->not->toBeNull();
    }
});

test('all movement types share a global creation order independent of business dates and IDs', function () {
    $keys = [];
    foreach (['expense', 'payment', 'currency_exchange', 'transfer', 'owner_withdrawal'] as $index => $type) {
        $this->travelTo(now()->setTime(16, $index + 5));
        if ($type === 'payment') {
            $record = ($this->payment)(['paid_at' => '2026-11-08']);
            $keys[] = "payment-$record->id";
        } else {
            $record = ($this->movement)([
                'type' => $type, 'transacted_at' => '2026-11-09 23:59:59',
                'to_account' => in_array($type, ['transfer', 'currency_exchange']) ? 'bank' : null,
                'from_amount' => 100, 'from_currency' => 'USD', 'to_amount' => 270,
                'to_currency' => 'GEL', 'exchange_rate' => 2.7,
            ]);
            $keys[] = "transaction-$record->id";
        }
    }
    Livewire::test(ListPartnerFinance::class)
        ->assertCanSeeTableRecords(($this->entries)(array_reverse($keys)), inOrder: true);
});

test('global ordering and deterministic timestamp ties are applied before pagination', function () {
    $keys = [];
    foreach (range(1, 32) as $index) {
        $this->travelTo(now()->setTime(16, intdiv($index - 1, 4)));
        $payment = $index % 2 === 0;
        $record = $payment ? ($this->payment)() : ($this->movement)();
        $keys[] = ['key' => ($payment ? 'payment-' : 'transaction-').$record->id, 'created_at' => $record->created_at->toDateTimeString()];
    }
    $expected = collect($keys)->sort(fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($a['key'], $b['key']))->pluck('key');
    $page = Livewire::test(ListPartnerFinance::class)->set('tableRecordsPerPage', 10);
    $seen = [];
    foreach (range(1, 4) as $number) {
        $page->call('setPage', $number);
        $actual = $page->instance()->getTableRecords()->pluck('entry_key')->all();
        expect($actual)->toBe($expected->slice(($number - 1) * 10, 10)->values()->all());
        $seen = [...$seen, ...$actual];
    }
    expect($seen)->toBe($expected->all())->and(array_unique($seen))->toHaveCount(32);
});

test('page periods and table date filters still use business dates rather than creation dates', function () {
    $expense = ($this->movement)(['transacted_at' => '2026-10-25 23:59:59']);
    $payment = ($this->payment)(['paid_at' => '2026-10-25']);
    ($this->payment)(['paid_at' => '2026-10-24']);
    ($this->movement)(['transacted_at' => '2026-10-26']);
    $expected = ($this->entries)(["payment-$payment->id", "transaction-$expense->id"]);
    Livewire::test(ListPartnerFinance::class)->set('dateFrom', '2026-10-25')->set('dateUntil', '2026-10-25')
        ->assertCountTableRecords(2)->assertCanSeeTableRecords($expected, inOrder: true);
    Livewire::test(ListPartnerFinance::class)->set('period', '1_month')
        ->filterTable('transacted_at', ['from' => '2026-10-25', 'until' => '2026-10-25'])
        ->assertCountTableRecords(2)->assertCanSeeTableRecords($expected, inOrder: true);
});

test('movement detail modal orders creation times before paginating and retains business date filters', function () {
    $ids = [];
    foreach (range(1, 27) as $index) {
        $this->travelTo(now()->setTime(16, $index));
        $record = ($this->movement)([
            'type' => 'transfer', 'category' => 'bank_deposit', 'to_account' => 'bank',
            'transacted_at' => '2026-11-09 16:'.str_pad(59 - $index, 2, '0', STR_PAD_LEFT).':00',
        ]);
        $ids[] = $record->id;
    }
    ($this->movement)(['type' => 'transfer', 'category' => 'bank_deposit', 'to_account' => 'bank', 'transacted_at' => '2026-10-01']);
    $page = Livewire::test(ListPartnerFinance::class);
    $data = $page->instance()->movementDetails(['movement' => 'bank_deposit']);
    expect($data['transactions']->pluck('id')->all())->toBe(array_slice(array_reverse($ids), 0, 25))
        ->and($data['movement']['totals']['USD'])->toBe(5400.0);
    $page->call('setPage', 2, 'israeliMovementPage');
    expect($page->instance()->movementDetails(['movement' => 'bank_deposit'])['transactions']->pluck('id')->all())
        ->toBe(array_slice(array_reverse($ids), 25));
});

test('reading ordered transactions preserves financial totals balances and every source record', function () {
    ($this->payment)();
    ($this->movement)();
    ($this->movement)(['type' => 'transfer', 'category' => 'bank_deposit', 'to_account' => 'bank', 'amount' => 100]);
    $summary = app(PartnerFinanceSummary::class);
    $snapshot = fn () => collect(['partner_patient_payments', 'partner_finance_transactions'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $balances = $summary->accountBalances();
    Livewire::test(ListPartnerFinance::class)->instance()->movementDetails(['movement' => 'bank_deposit']);
    expect($snapshot())->toBe($before)->and($summary->accountBalances())->toBe($balances)
        ->and($summary->receivedTotals())->toBe(['GEL' => 0.0, 'USD' => 5000.0])
        ->and($summary->expenseTotals())->toBe(['GEL' => 0.0, 'USD' => 200.0]);
});

test('saving a payment from a later page immediately returns to the newest transaction', function () {
    foreach (range(1, 12) as $index) {
        ($this->movement)();
    }
    $page = Livewire::test(ListPartnerFinance::class)->set('tableRecordsPerPage', 10)->call('setPage', 2);
    $this->travelTo(now()->addMinute());
    $page->callAction('addPatientPayment', [
        'patient_id' => $this->patient->id, 'amount' => 3000, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => '2026-11-09',
    ])->assertHasNoActionErrors();
    expect($page->instance()->getTableRecords()->currentPage())->toBe(1)
        ->and($page->instance()->getTableRecords()->first()->transaction_type)->toBe('payment');
    $this->travelTo(now()->addMinute());
    $payment = ($this->payment)();
    $page->call('$refresh');
    expect($page->instance()->getTableRecords()->first()->entry_key)->toBe("payment-$payment->id");
});

test('saving an expense from a later page immediately returns to the newest transaction', function () {
    foreach (range(1, 12) as $index) {
        ($this->payment)();
    }
    $page = Livewire::test(ListPartnerFinance::class)->set('tableRecordsPerPage', 10)->call('setPage', 2);
    $this->travelTo(now()->addMinute());
    $dimensions = app(ExpenseDimensions::class);
    $direction = $dimensions->id('direction', 'general');
    $page->callAction('useIsraeliFunds', [
        'transacted_at' => '2026-11-08', 'operation_type' => 'other',
        'expense_direction_id' => $direction,
        'expense_type_id' => $dimensions->id('type', 'other', $direction),
        'payment_mode' => 'direct_usd', 'actual_amount' => 200, 'notes' => 'Newest expense',
    ])->assertHasNoActionErrors();
    expect($page->instance()->getTableRecords()->currentPage())->toBe(1)
        ->and($page->instance()->getTableRecords()->first()->notes)->toBe('Newest expense');
});
