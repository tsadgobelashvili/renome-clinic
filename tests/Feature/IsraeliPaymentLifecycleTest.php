<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\PaymentsRelationManager;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\PartnerPaymentAudit;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\Visit;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\OriginalRecordEditor;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliPatientPaymentLifecycle;
use App\Services\IsraeliPatientPaymentService;
use App\Services\PartnerFinanceSummary;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']);
    $this->actingAs($this->owner);
    $this->patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'First', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->other = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Second', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->payment = app(IsraeliPatientPaymentService::class)->create(['patient_id' => $this->patient->id,
        'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => '2026-11-17 09:00:00', 'notes' => 'Original note']);
    $this->lifecycle = app(IsraeliPatientPaymentLifecycle::class);
});

function israeliCorrection(PartnerPatientPayment $payment, array $extra = []): array
{
    return array_replace(['expected_version' => $payment->lock_version ?? 0, 'operation_key' => (string) Str::uuid()], $extra);
}

test('editing replaces the original patient amount currency date method and notes and reconciles all receipt readers', function () {
    $created = $this->payment->created_at->toISOString();
    $result = $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, [
        'patient_id' => $this->other->id, 'amount' => 250, 'currency' => 'GEL', 'payment_method' => 'bank_transfer',
        'account' => 'bank', 'paid_at' => '2026-11-16 14:35:00', 'notes' => 'Corrected note', 'created_at' => '2000-01-01',
    ]));
    expect($result->id)->toBe($this->payment->id)->and($result->created_at->toISOString())->toBe($created)
        ->and($result->patient_id)->toBe($this->other->id)->and($result->amount)->toBe('250.00')
        ->and($result->paid_at->format('Y-m-d H:i:s'))->toBe('2026-11-16 14:35:00')
        ->and($this->patient->getPartnerPaymentTotals())->toBe([])->and($this->other->getPartnerPaymentTotals())->toBe(['GEL' => 250.0]);
    $summary = app(PartnerFinanceSummary::class);
    expect($summary->receivedTotals())->toBe(['GEL' => 250.0, 'USD' => 0.0])
        ->and($summary->accountBalances()['cash'])->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and($summary->accountBalances()['bank'])->toBe(['GEL' => 250.0, 'USD' => 0.0])
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and($summary->receivedTotals('2026-11-17', '2026-11-17')['GEL'])->toBe(0.0);
    $audit = PartnerPaymentAudit::sole();
    expect($audit->old_values['patient_id'])->toBe($this->patient->id)->and($audit->old_values['amount'])->toBe('100.00')
        ->and($audit->new_values['patient_id'])->toBe($this->other->id)->and($audit->new_values['amount'])->toBe('250.00')
        ->and((int) $audit->user_id)->toBe($this->owner->id);
    expect(PartnerPatientPayment::count())->toBe(1)->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->pnlTotals(null, null, 'all', 'GEL', 'israeli')->sole()->revenue)->toBe(250.0)
        ->and($ledger->pnlTotals(null, null, 'all', 'USD', 'israeli'))->toBeEmpty();
});

test('an identical successful request can be retried without applying another financial or audit effect', function () {
    $data = israeliCorrection($this->payment, ['amount' => 140]);
    $first = $this->lifecycle->update($this->payment->id, $data);
    $second = $this->lifecycle->update($this->payment->id, $data);
    expect($first->lock_version)->toBe(1)->and($second->lock_version)->toBe(1)
        ->and(PartnerPaymentAudit::count())->toBe(1)->and(PartnerPatientPayment::count())->toBe(1)
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals()['USD'])->toBe(140.0);
    expect(fn () => $this->lifecycle->update($this->payment->id, array_replace($data, ['amount' => 999])))
        ->toThrow(ValidationException::class);
});

test('cancellation is soft auditable idempotent and excluded from balances but retained in history', function () {
    $created = $this->payment->created_at->toISOString();
    $data = israeliCorrection($this->payment, ['reason' => 'Entered in error']);
    $cancelled = $this->lifecycle->cancel($this->payment->id, $data);
    $this->lifecycle->cancel($this->payment->id, $data);
    expect($cancelled->trashed())->toBeTrue()->and($cancelled->cancelled_by)->toBe($this->owner->id)
        ->and($cancelled->created_at->toISOString())->toBe($created)->and($cancelled->amount)->toBe('100.00')
        ->and($cancelled->cancellation_reason)->toBe('Entered in error')->and(PartnerPatientPayment::count())->toBe(0)
        ->and(PartnerPatientPayment::withTrashed()->count())->toBe(1)->and(PartnerPaymentAudit::count())->toBe(1)
        ->and(app(PartnerFinanceSummary::class)->receivedTotals())->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and(app(PartnerFinanceSummary::class)->accountBalances()['cash']['USD'])->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->balances('israeli')['USD'])->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(0.0)
        ->and($this->patient->getPartnerPaymentTotals())->toBe([])
        ->and(PartnerFinanceEntry::where('source_type', 'payment')->count())->toBe(1);
    $page = Livewire::test(ListPartnerFinance::class)->assertSee(__('israeli-payments.cancelled'))
        ->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$this->payment->id));
    $page->assertMountedActionModalSee('Entered in error');
    expect($page->getMountedActionModalHtml())->not->toContain('wire:click="mountAction(\'editPayment');
    expect(fn () => $this->lifecycle->cancel($this->payment->id, israeliCorrection($cancelled, ['reason' => 'Again'])))
        ->toThrow(ValidationException::class);
    expect(fn () => $cancelled->forceDelete())->toThrow(ValidationException::class);
    expect(app(AccountingLedger::class)->pnlTotals(null, null, 'all', '', 'israeli'))->toBeEmpty()
        ->and(PartnerFinanceTransaction::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0)
        ->and(CashboxTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('owner recovery reactivates the same receipt and preserves the cancellation audit', function () {
    $cancelled = $this->lifecycle->cancel($this->payment->id, israeliCorrection($this->payment, ['reason' => 'Wrong receipt']));
    $firstAudit = PartnerPaymentAudit::sole()->getAttributes();
    $restored = $this->lifecycle->restore($cancelled->id, israeliCorrection($cancelled, ['reason' => 'Confirmed with patient']));
    expect($restored->trashed())->toBeFalse()->and($restored->cancelled_at)->toBeNull()->and($restored->id)->toBe($this->payment->id)
        ->and(PartnerPaymentAudit::count())->toBe(2)->and(PartnerPaymentAudit::oldest('id')->first()->getAttributes())->toBe($firstAudit)
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals()['USD'])->toBe(100.0);
    expect(fn () => PartnerPaymentAudit::first()->update(['action' => 'changed']))->toThrow(ValidationException::class);
});

test('cancellation requires a reason and optimistic version', function (array $data) {
    expect(fn () => $this->lifecycle->cancel($this->payment->id, $data))->toThrow(ValidationException::class);
    expect(PartnerPatientPayment::count())->toBe(1)->and(PartnerPaymentAudit::count())->toBe(0);
})->with([
    [['expected_version' => 0, 'operation_key' => 'not-a-uuid', 'reason' => 'Bad token']],
    [['expected_version' => 0, 'operation_key' => 'fcb0b72a-bdb6-4bf8-9abf-05bdb6ccdd43', 'reason' => '']],
    [['operation_key' => 'fcb0b72a-bdb6-4bf8-9abf-05bdb6ccdd43', 'reason' => 'No version']],
]);

test('administrator can create and view payment details but cannot change cancel or restore receipts', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'locale' => 'ka']);
    Livewire::actingAs($admin);
    expect(Gate::allows('view', $this->payment))->toBeTrue()->and(Gate::allows('create', PartnerPatientPayment::class))->toBeTrue()
        ->and(Gate::allows('update', $this->payment))->toBeFalse()->and(Gate::allows('delete', $this->payment))->toBeFalse()
        ->and(Gate::allows('forceDelete', $this->payment))->toBeFalse();
    app(IsraeliPatientPaymentService::class)->create(['patient_id' => $this->patient->id,
        'amount' => 50, 'currency' => 'GEL', 'payment_method' => 'card', 'paid_at' => today()]);
    $profile = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->mountAction(TestAction::make('patientPaymentDetails')->table((string) -$this->payment->id));
    $profile->assertMountedActionModalSee('Original note');
    expect(\App\Filament\Actions\IsraeliPaymentDetailsAction::footer($this->payment))->toBe([]);
    foreach (['editPayment', 'cancelPayment'] as $action) {
        $profile->call('mountAction', $action)->assertActionNotMounted($action);
    }
    foreach (['update', 'cancel', 'restore'] as $method) {
        try {
            $this->lifecycle->{$method}($this->payment->id, israeliCorrection($this->payment, ['amount' => 5, 'reason' => 'Denied']));
            $this->fail('Expected server-side authorization');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
    }
    $this->get(\App\Filament\Resources\PartnerFinance\PartnerFinanceResource::getUrl())->assertForbidden();
    expect($this->payment->fresh()->amount)->toBe('100.00')->and(PartnerPaymentAudit::count())->toBe(0);
});

test('two open Owner editors reject stale concurrent submissions', function () {
    $action = TestAction::make('patientPaymentDetails')->table('payment-'.$this->payment->id);
    $first = Livewire::test(ListPartnerFinance::class)->mountAction($action)->mountAction('editPayment');
    $second = Livewire::test(ListPartnerFinance::class)->mountAction($action)->mountAction('editPayment');
    $first->fillForm(['amount' => 150])->callMountedAction()->assertHasNoFormErrors();
    $second->fillForm(['amount' => 200])->callMountedAction()->assertHasFormErrors(['payment']);
    expect($this->payment->fresh()->amount)->toBe('150.00')->and(PartnerPaymentAudit::count())->toBe(1);
});

test('an Owner editor rejects a role change before its Livewire submission', function () {
    $page = Livewire::test(ListPartnerFinance::class)
        ->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$this->payment->id))->mountAction('editPayment');
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $page->callMountedAction()->assertForbidden();
    expect($this->payment->fresh()->amount)->toBe('100.00')->and(PartnerPaymentAudit::count())->toBe(0);
});

test('the Owner edit form changes all supported fields and synchronizes the account with the payment method', function () {
    $page = Livewire::test(ListPartnerFinance::class)
        ->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$this->payment->id))
        ->mountAction('editPayment')->call('enableSchemaStateUpdateHooksForTesting')
        ->set('mountedActions.1.data.payment_method', 'card')
        ->assertSet('mountedActions.1.data.account', 'bank')
        ->fillForm(['patient_id' => $this->other->id, 'amount' => 275, 'currency' => 'GEL',
            'paid_at' => '2026-11-15 15:20:00', 'notes' => null])
        ->callMountedAction()->assertHasNoFormErrors();
    $payment = $this->payment->fresh();
    $page->assertDispatched('refreshPatientProfile');
    expect($payment->patient_id)->toBe($this->other->id)->and($payment->amount)->toBe('275.00')
        ->and($payment->currency)->toBe('GEL')->and($payment->payment_method)->toBe('card')
        ->and($payment->paid_at->format('Y-m-d H:i:s'))->toBe('2026-11-15 15:20:00')
        ->and($payment->notes)->toBeNull()->and(PartnerPaymentAudit::count())->toBe(1);
    $page->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$payment->id))
        ->mountAction('editPayment')->set('mountedActions.1.data.account', 'cash')
        ->assertSet('mountedActions.1.data.payment_method', 'cash')->callMountedAction()->assertHasNoFormErrors();
    expect($payment->fresh()->payment_method)->toBe('cash')
        ->and(app(PartnerFinanceSummary::class)->accountBalances()['cash']['GEL'])->toBe(275.0)
        ->and(app(PartnerFinanceSummary::class)->accountBalances()['bank']['GEL'])->toBe(0.0);
});

test('patient summary refreshes after a receipt correction or cancellation', function () {
    $profile = Livewire::test(ViewPatient::class, ['record' => $this->patient->getRouteKey()])->assertSee('$100.00');
    $updated = $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, ['amount' => 180]));
    $profile->dispatch('refreshPatientProfile')->assertSee('$180.00')->assertDontSee('$100.00');
    $this->lifecycle->cancel($updated->id, israeliCorrection($updated, ['reason' => 'Summary regression']));
    $profile->dispatch('refreshPatientProfile')->assertDontSee('$180.00');
});

test('visit-linked accounting is protected and invalid patient selections roll back the version claim', function () {
    $visit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'currency' => 'USD']);
    $this->payment->update(['visit_id' => $visit->id]);
    expect(fn () => $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, ['amount' => 5])))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->lifecycle->cancel($this->payment->id, israeliCorrection($this->payment, ['reason' => 'Protected'])))
        ->toThrow(ValidationException::class);
    $this->payment->update(['visit_id' => null]);
    $clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Only']);
    expect(fn () => $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, ['patient_id' => $clinic->id])))
        ->toThrow(ValidationException::class);
    expect($this->payment->fresh()->lock_version)->toBe(0)->and($this->payment->fresh()->amount)->toBe('100.00');
});

test('funds already consumed cannot be removed or moved to another account', function () {
    $expense = PartnerFinanceTransaction::create(['type' => 'expense', 'source' => 'israeli', 'transacted_at' => now(),
        'category' => 'materials', 'from_account' => 'cash', 'amount' => 90, 'currency' => 'USD']);
    foreach (['cancel', 'update'] as $method) {
        expect(fn () => $this->lifecycle->{$method}($this->payment->id,
            israeliCorrection($this->payment, ['amount' => 5, 'reason' => 'Would overdraw'])))
            ->toThrow(ValidationException::class);
    }
    expect($this->payment->fresh()->lock_version)->toBe(0)->and($this->payment->fresh()->amount)->toBe('100.00')
        ->and($expense->fresh()->amount)->toBe('90.00')->and(PartnerPaymentAudit::count())->toBe(0);
});

test('audit insertion failure rolls back the original receipt and version atomically', function () {
    $event = 'eloquent.creating: '.PartnerPaymentAudit::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic audit failure'));
    try {
        expect(fn () => $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, ['amount' => 200])))
            ->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($this->payment->fresh()->amount)->toBe('100.00')->and($this->payment->fresh()->lock_version)->toBe(0)
        ->and(PartnerPaymentAudit::count())->toBe(0);
});

test('the balance lock requires a transaction and a nested lifecycle mutation rolls back with its outer transaction', function () {
    // RefreshDatabase already holds an outer transaction. Use a fresh connection to check the contract.
    config(['database.connections.balance_lock_contract' => config('database.connections.sqlite')]);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('balance_lock_contract');
    try {
        expect(fn () => \App\Services\IsraeliFinanceBalanceLock::acquire())->toThrow(LogicException::class);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('balance_lock_contract');
    }
    expect(fn () => DB::transaction(function () {
        $this->lifecycle->cancel($this->payment->id, israeliCorrection($this->payment, ['reason' => 'Outer rollback']));
        throw new RuntimeException('Synthetic outer failure');
    }))->toThrow(RuntimeException::class);
    expect($this->payment->fresh()->lock_version)->toBe(0)->and($this->payment->fresh()->deleted_at)->toBeNull()
        ->and(PartnerPaymentAudit::count())->toBe(0)
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals()['USD'])->toBe(100.0);
});

test('editing and cancelling preserve global newest-created ordering and business-date filters', function () {
    $this->travel(1)->minutes();
    $latest = app(IsraeliPatientPaymentService::class)->create(['patient_id' => $this->patient->id,
        'amount' => 10, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today()]);
    $created = $this->payment->created_at->toISOString();
    $edited = $this->lifecycle->update($this->payment->id, israeliCorrection($this->payment, ['amount' => 150]));
    $this->lifecycle->cancel($edited->id, israeliCorrection($edited, ['reason' => 'Audit retained']));
    $page = Livewire::test(ListPartnerFinance::class);
    expect($page->instance()->getTableRecords()->pluck('entry_key')->all())
        ->toBe(['payment-'.$latest->id, 'payment-'.$this->payment->id]);
    expect(PartnerPatientPayment::withTrashed()->find($this->payment->id)->created_at->toISOString())->toBe($created);
    $page->set('dateFrom', '2026-11-18')->assertCanNotSeeTableRecords(['payment-'.$latest->id]);
});

test('the confirmation modal requires a cancellation reason and cancelled receipts remain visible to administrators', function () {
    $page = Livewire::test(ListPartnerFinance::class)
        ->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$this->payment->id))
        ->mountAction('cancelPayment')->callMountedAction()->assertHasFormErrors(['reason' => 'required']);
    $page->fillForm(['reason' => 'Confirmed cancellation'])->callMountedAction()->assertHasNoFormErrors()
        ->assertSee(__('israeli-payments.cancelled'));
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'locale' => 'ka']));
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->assertSee(__('israeli-payments.cancelled'))
        ->mountAction(TestAction::make('patientPaymentDetails')->table((string) -$this->payment->id))
        ->assertMountedActionModalSee('Confirmed cancellation');
    expect(PartnerPaymentAudit::count())->toBe(1);
});
