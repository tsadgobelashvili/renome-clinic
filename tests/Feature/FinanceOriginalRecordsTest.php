<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Services\BogBusinessApiService;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\EntryDetails;
use App\Services\Finance\OriginalRecordEditor;
use App\Services\FinanceUsdUsageService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\CashOverviewStorage;

beforeEach(function () {
    CashOverviewStorage::create();
    Http::preventStrayRequests();
    config(['app.timezone' => 'Asia/Tbilisi']);
    $this->travelTo(Carbon::parse('2026-09-14 12:00:00', 'Asia/Tbilisi'));
    $this->actingAs(User::forceCreate(['name' => 'Finance test', 'email' => 'finance@example.test', 'role' => User::ROLE_OWNER, 'is_active' => true]));
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('0.00');
    DB::table('cashbox_days')->insert(['id' => 1, 'date' => '2026-09-14', 'status' => 'open', 'opening_balance' => 1000, 'opening_balance_usd' => 100]);
    $this->editor = app(OriginalRecordEditor::class);
});

function originalFinance(array $attributes = []): int
{
    return DB::table('finance_transactions')->insertGetId(array_replace([
        'type' => 'income', 'transaction_date' => '2026-09-14 10:20:00', 'category' => 'other_income', 'amount' => 100,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier',
        'description' => 'Original description', 'note' => 'Original full notes', 'created_by' => auth()->id(),
    ], $attributes));
}

test('actual overview shows original details filters and exchange legs without changing card totals or duplicating mirrors', function () {
    $id = originalFinance();
    DB::table('cashbox_transactions')->insert(['finance_transaction_id' => $id, 'cashbox_day_id' => 1, 'type' => 'other_income',
        'transaction_date' => '2026-09-14 10:20:00', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'description' => 'Mirror']);
    DB::table('partner_finance_transactions')->insert(['source' => 'israeli', 'type' => 'currency_exchange', 'transacted_at' => '2026-09-14 11:00:00',
        'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'to_currency' => 'GEL',
        'from_amount' => 10, 'to_amount' => 27, 'exchange_rate' => 2.7, 'notes' => 'Exchange fixture']);
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')->assertSee('Original full notes')
        ->assertSee('14.09.2026 10:20')->assertSee('კურსი: 2.7')->assertSee('დეტალები / რედაქტირება');
    $totals = $page->viewData('figures');
    expect($page->viewData('overviewDetails')->count())->toBe(3);
    $page->set('overviewSearch', 'Original full')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1)
        ->assertViewHas('figures', $totals)->set('overviewSearch', '')->set('overviewType', 'currency_exchange')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2)->assertViewHas('figures', $totals)
        ->set('overviewType', '')->set('overviewClassification', 'other_income')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(EntryDetails::class)->query(app(CashOutflowReport::class)->movements('2026-09-14', '2026-09-14', 'all', 'all'))->get();
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});

test('actual Finance detail action opens the original record and its edit form saves through FinanceManager', function () {
    $id = originalFinance();
    $page = Livewire::test(Finance::class)->mountAction('financeRecord', ['key' => 'finance:'.$id])->assertActionMounted('financeRecord');
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Original full notes', 'Original description');
    $page->mountAction('editOriginal')->assertActionMounted('editOriginal')
        ->fillForm(['amount' => 125, 'note' => 'Corrected notes'])->callMountedAction()->assertHasNoActionErrors();
    expect(FinanceTransaction::find($id)->amount)->toBe('125.00')
        ->and(DB::table('cashbox_transactions')->where('finance_transaction_id', $id)->value('amount'))->toEqual(125)
        ->and(DB::table('finance_transactions')->count())->toBe(1);
});

test('expense drilldown displays and searches the stored category and subcategory without changing totals', function () {
    DB::table('expense_categories')->insert([
        ['id' => 1, 'name' => 'Surgery fixture', 'classification_dimension' => 'direction', 'classification_code' => 'surgery', 'parent_id' => null],
        ['id' => 2, 'name' => 'Materials fixture', 'classification_dimension' => 'type', 'classification_code' => 'materials', 'parent_id' => 1],
    ]);
    originalFinance(['type' => 'expense', 'category' => 'materials', 'expense_direction_id' => 1, 'expense_type_id' => 2]);
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'expenses')
        ->call('selectExpenseCategory', '1')->call('selectExpenseSubcategory', '2')
        ->assertSee('Surgery fixture')->assertSee('Materials fixture')->assertSee('Original full notes')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1);
    $figures = $page->viewData('figures');
    $page->set('overviewClassification', 'Materials fixture')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1)
        ->set('overviewSearch', 'Not present')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 0)
        ->assertViewHas('figures', $figures)->call('resetFilters')
        ->assertSet('overviewSearch', '')->assertSet('overviewClassification', '')->assertSet('overviewType', '');
});

test('payment correction keeps native splits cash mirrors and remaining amount synchronized', function () {
    DB::table('patient_groups')->insert(['id' => 1, 'name' => 'Clinic', 'slug' => 'clinic']);
    DB::table('patients')->insert(['id' => 1, 'first_name' => 'Test', 'last_name' => 'Patient', 'patient_group_id' => 1]);
    DB::table('visits')->insert(['id' => 1, 'patient_id' => 1, 'visit_date' => '2026-09-14', 'total_price' => 500, 'discount_amount' => 0, 'currency' => 'GEL']);
    DB::table('payments')->insert(['id' => 1, 'visit_id' => 1, 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'payment_date' => '2026-09-14', 'created_at' => now(), 'created_by' => auth()->id()]);
    DB::table('payment_splits')->insert(['payment_id' => 1, 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash']);
    $record = $this->editor->update('payment:1', ['payment_date' => '2026-09-14', 'comment' => 'Corrected split', 'visit_id' => 999,
        'splits' => [['amount' => 100, 'currency' => 'GEL', 'payment_method' => 'card'], ['amount' => 50, 'currency' => 'USD', 'payment_method' => 'cash', 'exchange_rate' => 2.7]]]);
    expect($record->amount)->toBe('235.00')->and($record->visit_id)->toBe(1)->and($record->visit->remaining_amount)->toBe(265.0);
    $mirrors = DB::table('cashbox_transactions')->where('payment_id', 1)->get();
    expect($mirrors)->toHaveCount(2)->and($mirrors->where('payment_method', 'cash')->sole()->currency)->toBe('USD')
        ->and($mirrors->sum('amount'))->toEqual(150)->and(Payment::count())->toBe(1);
    $this->editor->update('payment:1', ['splits' => [['amount' => 200, 'currency' => 'GEL', 'payment_method' => 'cash']]]);
    expect(DB::table('cashbox_transactions')->where('payment_id', 1)->count())->toBe(1)
        ->and(DB::table('cashbox_transactions')->where('payment_id', 1)->value('amount'))->toEqual(200);
    expect(fn () => $this->editor->update('payment:1', ['comment' => 'Must roll back',
        'splits' => [['amount' => 900, 'currency' => 'GEL', 'payment_method' => 'cash']]]))->toThrow(ValidationException::class);
    expect(Payment::find(1)->comment)->toBe('Corrected split')->and(Payment::find(1)->amount)->toBe('200.00');
    DB::table('cashbox_days')->where('id', 1)->update(['status' => 'closed']);
    expect(fn () => $this->editor->update('payment:1', ['payment_date' => '2026-09-15',
        'splits' => [['amount' => 150, 'currency' => 'GEL', 'payment_method' => 'cash']]]))->toThrow(ValidationException::class);
    expect(Payment::find(1)->amount)->toBe('200.00');
});

test('closed salary advance RS and linked records reject edits on the server', function () {
    foreach (['purchase_id', 'salary_payout_allocation_id', 'payroll_entry_id', 'salary_settlement_id', 'employee_salary_settlement_id', 'lab_salary_settlement_id'] as $field) {
        $id = originalFinance([$field => 1]);
        expect($this->editor->reason(FinanceTransaction::find($id)))->not->toBeNull();
        expect(fn () => $this->editor->update('finance:'.$id, ['amount' => 500]))->toThrow(ValidationException::class);
        expect(FinanceTransaction::find($id)->amount)->toBe('100.00');
    }
    $id = originalFinance();
    DB::table('cashbox_days')->where('id', 1)->update(['status' => 'closed']);
    expect(fn () => $this->editor->update('finance:'.$id, ['amount' => 500]))->toThrow(ValidationException::class);
    DB::table('partner_finance_transactions')->insert(['id' => 1, 'source' => 'clinic', 'type' => 'employee_advance', 'employee_advance_id' => 1]);
    expect(fn () => $this->editor->update('partner:1', ['amount' => 500]))->toThrow(ValidationException::class);
    $this->actingAs(new User(['role' => User::ROLE_ADMINISTRATOR, 'is_active' => true]));
    expect(fn () => $this->editor->update('finance:'.$id, ['amount' => 500]))->toThrow(HttpException::class);
});

test('Israeli original receipts preserve native totals and prohibit changing patient or accessing Clinic entries', function () {
    DB::table('patient_groups')->insert(['id' => 1, 'name' => 'Israeli', 'slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    DB::table('patients')->insert(['id' => 1, 'first_name' => 'Test', 'last_name' => 'Israeli', 'patient_group_id' => 1]);
    DB::table('partner_patient_payments')->insert(['id' => 1, 'patient_id' => 1, 'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now()]);
    $this->editor->update('partner-payment:1', ['patient_id' => 999, 'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'card'], israeliOnly: true);
    expect(PartnerPatientPayment::find(1)->patient_id)->toBe(1)
        ->and(Patient::find(1)->getPartnerPaymentTotals())->toBe(['GEL' => 200.0]);
    $id = originalFinance();
    expect(fn () => $this->editor->find('finance:'.$id, israeliOnly: true))->toThrow(HttpException::class);
});

test('Israeli Finance table resolves each original receipt for its detail and edit actions', function () {
    DB::statement("CREATE VIEW partner_finance_entries AS SELECT 'payment:' || id AS entry_key, 'payment' AS source_type,
        id AS source_id, 'payment' AS transaction_type, paid_at AS transacted_at, patient_id, amount, currency,
        'cash' AS from_account, NULL AS to_account, NULL AS category, payment_method, NULL AS from_amount,
        NULL AS from_currency, NULL AS to_amount, NULL AS to_currency, NULL AS exchange_rate, notes,
        'israeli' AS source, NULL AS recipient, NULL AS lab_salary_settlement_id, NULL AS created_by FROM partner_patient_payments");
    DB::table('patient_groups')->insert(['id' => 1, 'name' => 'Israeli', 'slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    DB::table('patients')->insert(['id' => 1, 'first_name' => 'Israeli', 'last_name' => 'Patient', 'patient_group_id' => 1]);
    DB::table('partner_patient_payments')->insert(['id' => 1, 'patient_id' => 1, 'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(), 'notes' => 'Original Israeli receipt']);
    $page = Livewire::test(ListPartnerFinance::class)->assertSee('Original Israeli receipt')
        ->searchTable('Israeli Patient')->assertCanSeeTableRecords(['payment:1'])
        ->mountAction(TestAction::make('financeRecord')->table('payment:1'));
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Original Israeli receipt');
    $page->mountAction('editOriginal')->fillForm(['amount' => 175, 'notes' => 'Edited Israeli receipt'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(PartnerPatientPayment::find(1)->amount)->toBe('175.00')->and(PartnerPatientPayment::count())->toBe(1);
});

test('standalone exchange edits update balances while composite exchanges remain protected', function () {
    DB::table('partner_patient_payments')->insert(['amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now()]);
    DB::table('partner_finance_transactions')->insert(['id' => 1, 'source' => 'israeli', 'type' => 'currency_exchange',
        'transacted_at' => '2026-09-14 11:00:00', 'created_by' => auth()->id(), 'from_account' => 'cash', 'to_account' => 'cash',
        'from_currency' => 'USD', 'to_currency' => 'GEL', 'from_amount' => 10, 'to_amount' => 27, 'exchange_rate' => 2.7]);
    $this->editor->update('partner:1', ['from_amount' => 20, 'to_amount' => 56, 'exchange_rate' => 2.8], israeliOnly: true);
    expect(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 56.0, 'USD' => 80.0]);
    expect(fn () => $this->editor->update('partner:1', ['from_amount' => 150, 'to_amount' => 420], israeliOnly: true))->toThrow(ValidationException::class);
    expect(PartnerFinanceTransaction::find(1)->from_amount)->toBe('20.00');
    DB::table('partner_finance_transactions')->insert(['id' => 2, 'source' => 'israeli', 'type' => 'transfer',
        'transacted_at' => '2026-09-14 11:00:00', 'created_by' => auth()->id(), 'amount' => 50, 'currency' => 'GEL', 'from_account' => 'cash', 'to_account' => 'bank']);
    expect(fn () => $this->editor->update('partner:1', ['notes' => 'Cannot detach'], israeliOnly: true))->toThrow(ValidationException::class);
});
