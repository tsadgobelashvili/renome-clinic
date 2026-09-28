<?php
use App\Support\GroupedLedgerRows;
use App\Models\User;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Data\BankTransactionData;
use App\Services\Bank\BankIngestionService;
use App\Filament\Pages\Bank;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
uses(RefreshDatabase::class);

test('grouping preserves all members across the original page boundary and separates currencies', function () {
    $query = null;
    for ($i = 0; $i < 30; $i++) {
        $row = DB::query()->selectRaw("? AS entry_key, ? AS entry_date, ? AS counterparty, ? AS currency, ? AS amount", [(string) $i, '2026-09-28', 'Employee', 'GEL', 10]);
        $query = $query ? $query->unionAll($row) : $row;
    }
    $query->unionAll(DB::query()->selectRaw("'usd' AS entry_key, '2026-09-28' AS entry_date, 'Employee' AS counterparty, 'USD' AS currency, 5 AS amount"));
    $groups = GroupedLedgerRows::paginate(DB::query()->fromSub($query, 'entries')->select('*'), 'counterparty', 'entry_date');
    expect($groups->total())->toBe(2);
    $gel = $groups->firstWhere('currency', 'GEL');
    expect((float) $gel->total)->toBe(300.0)->and($gel->rows)->toHaveCount(30);
});

test('bank page collapses card settlements across pages and keeps original rows', function () {
    $this->travelTo(now()->setDate(2026, 9, 28));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $category = BankCategory::create(['name' => 'Card settlement test', 'accounting_treatment' => 'settlement', 'active' => true]);
    $rows = [];
    for ($i = 0; $i < 30; $i++) {
        $rows[] = new BankTransactionData(['operation_id' => 'compact-'. $i, 'transaction_date' => '2026-09-28 12:00:00',
            'direction' => 'inflow', 'amount' => '10.00', 'currency' => 'GEL', 'description' => 'Card receipt '. $i]);
    }
    app(BankIngestionService::class)->ingest($rows, 'api');
    BankTransaction::query()->update(['bank_category_id' => $category->id]);
    Livewire::test(Bank::class)->assertSee('30 ოპერაცია')->assertSee('300.00')->assertSee('Card receipt 29')
        ->assertViewHas('transactions', fn ($groups) => $groups->total() === 1 && $groups->first()->rows->count() === 30);
});
