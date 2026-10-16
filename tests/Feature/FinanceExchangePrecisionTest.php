<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceUsdUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-16 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create(['first_name' => 'Precision', 'last_name' => 'Test', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $patient->partnerPayments()->create(['amount' => 20000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now()]);
});

test('exchange multiplies the full rate and rounds only the final GEL amount', function ($usd, $rate, $gel) {
    expect(FinanceUsdUsageService::calculateReceivedGel($usd, $rate))->toBe($gel);
})->with([
    [11600, '2.595', 30102.0],
    [11600, '2.600', 30160.0],
    [11600, '2.5957', 30110.12],
    [11600, '2.595789', 30111.15],
    ['1.01', '2.5957', 2.62],
    ['0.01', '2.5', 0.03],
]);

test('Main Finance preview and saved exchange keep the entered rate', function () {
    Livewire::test(Finance::class)->mountAction('usdUsage')
        ->setActionData(['source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => 11600, 'exchange_rate' => '2.595'])
        ->assertActionDataSet(['exchange_rate' => '2.595', 'received_gel_amount' => 30102.0])
        ->callMountedAction()->assertHasNoActionErrors();
    $exchange = PartnerFinanceTransaction::where('type', 'currency_exchange')->sole();
    expect($exchange->exchange_rate)->toBe('2.595000')->and($exchange->to_amount)->toBe('30102.00');
    expect(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 30102.0, 'USD' => 8400.0]);
});

test('Israeli exchange preview preserves rate and explicit actual spending separately', function () {
    Livewire::test(ListPartnerFinance::class)->mountAction('useIsraeliFunds')
        ->setActionData(['operation_type' => 'bank_deposit', 'payment_mode' => 'exchange_usd_gel',
            'usd_amount' => 11600, 'exchange_rate' => '2.595', 'actual_amount' => 30160])
        ->assertActionDataSet(['exchange_rate' => '2.595', 'received_gel_amount' => 30102.0, 'actual_amount' => 30160])
        // Preserve the existing spending cap; actual received proceeds need a separate decision.
        ->setActionData(['actual_amount' => 30000])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(PartnerFinanceTransaction::where('type', 'currency_exchange')->sole()->exchange_rate)->toBe('2.595000')
        ->and(PartnerFinanceTransaction::where('type', 'currency_exchange')->sole()->to_amount)->toBe('30102.00')
        ->and(PartnerFinanceTransaction::where('type', 'transfer')->sole()->amount)->toBe('30000.00')
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 102.0, 'USD' => 8400.0]);
});

test('service stores higher precision rates without a money conversion and preserves PnL', function () {
    $before = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $result = app(FinanceUsdUsageService::class)->recordIsraeliOperation(['operation_type' => 'bank_deposit',
        'payment_mode' => 'exchange_usd_gel', 'usd_amount' => 11600, 'exchange_rate' => '2.5957',
        'actual_amount' => 30000, 'transacted_at' => now()]);
    expect($result['exchange']->fresh()->exchange_rate)->toBe('2.595700')
        ->and($result['exchange']->fresh()->to_amount)->toBe('30110.12')
        ->and($result['movement']->amount)->toBe('30000.00')
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($before);
});

test('new exchanges leave existing historical rates and monetary amounts unchanged', function () {
    $old = PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'currency_exchange', 'transacted_at' => now()->subDay(),
        'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'from_amount' => 11600,
        'to_currency' => 'GEL', 'to_amount' => 30160, 'exchange_rate' => '2.595']);
    $snapshot = $old->fresh()->getRawOriginal();
    app(FinanceUsdUsageService::class)->recordIsraeliOperation(['operation_type' => 'bank_deposit',
        'payment_mode' => 'exchange_usd_gel', 'usd_amount' => 100, 'exchange_rate' => '2.595',
        'actual_amount' => 200, 'transacted_at' => now()]);
    expect($old->fresh()->getRawOriginal())->toEqual($snapshot);
});

test('permitted exchange edits calculate using all six stored rate decimals', function () {
    $exchange = PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'currency_exchange', 'transacted_at' => now(),
        'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'from_amount' => 11600,
        'to_currency' => 'GEL', 'to_amount' => 30160, 'exchange_rate' => '2.6']);
    $updated = app(FinanceUsdUsageService::class)->updateMovement($exchange, ['exchange_rate' => '2.595789']);
    expect($updated->exchange_rate)->toBe('2.595789')->and($updated->to_amount)->toBe('30111.15');
});
