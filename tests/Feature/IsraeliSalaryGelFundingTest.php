<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\SalarySettlementService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function gelSalaryFundingFixture(float $israeli): array
{
    $doctor = Doctor::create(['first_name' => 'David', 'last_name' => 'Chumburidze', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Funding', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    $work = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 8]);
    if ($israeli > 0) {
        $patient->partnerPayments()->create(['amount' => $israeli, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
    }
    $patient->partnerPayments()->create(['amount' => 250, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now()]);
    foreach ([[1000, 'GEL', 'cash'], [200, 'USD', 'cash'], [300, 'GEL', 'card']] as [$amount, $currency, $method]) {
        app(FinanceManager::class)->create([
            'type' => 'income', 'category' => 'other_income', 'transaction_date' => now(),
            'amount' => $amount, 'currency' => $currency, 'payment_method' => $method, 'cash_source' => 'current_cashier',
        ]);
    }

    return [$doctor, $patient, $work];
}

function settleGelFundedSalary(Doctor $doctor): SalarySettlement
{
    return app(SalarySettlementService::class)->settle(
        $doctor->id, today()->toDateString(), today()->toDateString(), 40, null, null, PatientGroup::ISRAEL_PARTNER_SLUG, 'GEL',
    )[0];
}

test('Israeli GEL salary uses the funding priority with exact snapshots and idempotent reversal', function (float $available, float $israeliUsed, float $clinicUsed) {
    $this->travelTo('2026-09-07 10:00:00');
    [$doctor, $patient, $work] = gelSalaryFundingFixture($available);
    $finance = app(FinanceUsdUsageService::class);
    $settlement = settleGelFundedSalary($doctor);
    $clinicMovement = $settlement->clinicFinanceTransaction;

    expect((float) $settlement->total_paid_gel)->toBe(800.0)
        ->and((float) $settlement->israeli_gel_used)->toBe($israeliUsed)
        ->and((float) $settlement->clinic_gel_used)->toBe($clinicUsed)
        ->and($settlement->doctor_id)->toBe($doctor->id)
        ->and($settlement->settled_at->toDateString())->toBe('2026-09-07')
        ->and($finance->cashBalances('israeli'))->toBe(['GEL' => $available - $israeliUsed, 'USD' => 250.0])
        ->and($finance->cashBalances('clinic'))->toBe(['GEL' => 1000.0 - $clinicUsed, 'USD' => 200.0]);
    if ($israeliUsed > 0) {
        expect((float) $settlement->partnerFinanceTransaction->amount)->toBe($israeliUsed)
            ->and($settlement->partnerFinanceTransaction->category)->toBe('doctor_salary');
    } else {
        expect($settlement->partnerFinanceTransaction)->toBeNull();
    }
    if ($clinicUsed > 0) {
        expect((float) $clinicMovement->amount)->toBe($clinicUsed)
            ->and($clinicMovement->payment_method)->toBe('cash')
            ->and($clinicMovement->cash_source)->toBe('current_cashier')
            ->and($clinicMovement->currency)->toBe('GEL')
            ->and((float) $clinicMovement->cashboxTransaction->amount)->toBe($clinicUsed);
    } else {
        expect($clinicMovement)->toBeNull();
    }

    $finance->recordIsraeliDoctorSalary($settlement);
    $finance->recordIsraeliDoctorSalary($settlement->fresh());
    expect(PartnerFinanceTransaction::query()->where('salary_settlement_id', $settlement->id)->count())->toBe($israeliUsed > 0 ? 1 : 0)
        ->and(FinanceTransaction::query()->where('salary_settlement_id', $settlement->id)->count())->toBe($clinicUsed > 0 ? 1 : 0)
        ->and(fn () => settleGelFundedSalary($doctor))->toThrow(ValidationException::class);

    expect(app(SalarySettlementService::class)->undo($settlement->id, $doctor->id))->toBeTrue()
        ->and(app(SalarySettlementService::class)->undo($settlement->id, $doctor->id))->toBeFalse()
        ->and($finance->recordIsraeliDoctorSalary($settlement))->toBeNull()
        ->and($finance->cashBalances('israeli'))->toBe(['GEL' => $available, 'USD' => 250.0])
        ->and($finance->cashBalances('clinic'))->toBe(['GEL' => 1000.0, 'USD' => 200.0])
        ->and($work->fresh()->salarySettlementItem)->toBeNull()
        ->and((float) FinanceTransaction::query()->where('payment_method', 'card')->sum('amount'))->toBe(300.0)
        ->and(FinanceTransaction::query()->whereNotNull('reversal_of_finance_transaction_id')->count())->toBe($clinicUsed > 0 ? 1 : 0);
    if ($clinicMovement) {
        expect((float) $clinicMovement->fresh()->reversal->amount)->toBe($clinicUsed)
            ->and($clinicMovement->fresh()->reversal->cashboxTransaction->currency)->toBe('GEL');
    }
})->with(['Israeli only' => [1000.0, 800.0, 0.0], 'split' => [300.0, 300.0, 500.0], 'Clinic only' => [0.0, 0.0, 800.0]]);

test('GEL salary funding rolls back both sources when Clinic cash posting fails', function () {
    $this->travelTo('2026-09-07 10:00:00');
    [$doctor, $patient, $work] = gelSalaryFundingFixture(300);
    app(CashboxManager::class)->today()->update(['status' => 'closed']);
    expect(fn () => settleGelFundedSalary($doctor))->toThrow(ValidationException::class)
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(0)
        ->and(FinanceTransaction::query()->where('type', 'expense')->count())->toBe(0)
        ->and($work->fresh()->salarySettlementItem)->toBeNull();
});

test('undo refunds Clinic GEL on a new day without editing a closed cashbox day', function () {
    $this->travelTo('2026-09-07 10:00:00');
    [$doctor] = gelSalaryFundingFixture(300);
    $settlement = settleGelFundedSalary($doctor);
    $expense = $settlement->clinicFinanceTransaction;
    $oldCashboxId = $expense->cashboxTransaction->id;
    app(CashboxManager::class)->today()->update(['status' => 'closed']);
    $this->travelTo('2026-09-08 10:00:00');
    app(SalarySettlementService::class)->undo($settlement->id, $doctor->id);
    expect(CashboxTransaction::query()->find($oldCashboxId))->not->toBeNull()
        ->and($expense->fresh()->reversal->cashboxTransaction->day->date->toDateString())->toBe('2026-09-08')
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(1000.0);
});

test('salary history shows the saved GEL funding split', function () {
    $this->travelTo('2026-09-07 10:00:00');
    [$doctor] = gelSalaryFundingFixture(300);
    settleGelFundedSalary($doctor);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))->test(DoctorCompensation::class)
        ->call('openDoctorSalary', $doctor->id, 'israeli')
        ->call('toggleDoctorSalaryHistory', $doctor->id)
        ->assertMountedActionModalSee(['800.00 GEL paid', '300.00 Israeli', '500.00 Clinic']);
});
