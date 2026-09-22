<?php

use App\Filament\Resources\TreatmentCases\Pages\UncategorizedProcedures;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProcedureClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->makeVisit = function (array $items, string $currency = 'GEL', array $attributes = []): Visit {
        $patient = Patient::create(['first_name' => 'Amounts', 'last_name' => 'Patient']);
        $visit = Visit::create([...[
            'patient_id' => $patient->id, 'visit_date' => today()->subYears(2), 'visit_type' => 'treatment',
            'currency' => $currency, 'total_price' => collect($items)->sum(fn ($item) => $item[1] * ($item[2] ?? 1)),
        ], ...$attributes]);
        foreach ($items as [$name, $price, $quantity]) {
            $visit->treatmentCaseItems()->create(['custom_service_name' => $name, 'quantity' => $quantity,
                'unit_price' => $price, 'currency' => $currency]);
        }

        return $visit;
    };
    $this->pay = function (Visit $visit, float $amount, ?string $currency = null): Payment {
        $payment = new Payment(['visit_id' => $visit->id, 'amount' => $amount, 'currency' => $currency ?? $visit->currency,
            'payment_date' => today(), 'payment_method' => 'cash']);
        $payment->skipCashboxSync = true;
        $payment->save();

        return $payment;
    };
});

test('historical procedure charged and paid totals aggregate payments once and exclude deleted payments', function () {
    $visit = ($this->makeVisit)([['Implantation', 2500, 1]]);
    ($this->pay)($visit, 1000);
    ($this->pay)($visit, 500);
    $deleted = ($this->pay)($visit, 200);
    Payment::withoutEvents(fn () => $deleted->delete());
    ($this->pay)($visit, 20, 'USD'); // Visit::paid_amount ignores other-currency payments.
    $row = ProcedureClassification::uncategorized()->sole();
    expect((float) $row->charged_gel)->toBe(2500.0)->and((float) $row->paid_gel)->toBe($visit->fresh()->paid_amount)
        ->and((float) $row->paid_gel)->toBe(1500.0)->and((int) $row->usage_count)->toBe(1);
    Livewire::test(UncategorizedProcedures::class)->assertSee('2,500.00')->assertSee('1,500.00');
});

test('multiple procedures show only matching charges and do not invent per-procedure payment allocations', function () {
    $visit = ($this->makeVisit)([['Implantation', 2500, 1], ['Other work', 5000, 1]]);
    ($this->pay)($visit, 1000);
    ($this->pay)($visit, 500);
    $rows = ProcedureClassification::uncategorized()->get()->keyBy('procedure_name');
    expect((float) $rows['Implantation']->charged_gel)->toBe(2500.0)
        ->and((float) $rows['Other work']->charged_gel)->toBe(5000.0)
        ->and(ProcedureClassification::financialLines($rows['Implantation'], 'paid'))->toBe(['— GEL'])
        ->and(ProcedureClassification::financialLines($rows['Other work'], 'paid'))->toBe(['— GEL']);
});

test('repeated matching items count the visit payment only once and currencies stay separate', function () {
    $visit = ($this->makeVisit)([['Implantation', 1000, 1], [' implantation ', 500, 2]]);
    ($this->pay)($visit, 1500);
    $usd = ($this->makeVisit)([['Implantation', 100, 1]], 'USD');
    ($this->pay)($usd, 60);
    $row = ProcedureClassification::uncategorized()->sole();
    expect((float) $row->charged_gel)->toBe(2000.0)->and((float) $row->paid_gel)->toBe(1500.0)
        ->and((float) $row->charged_usd)->toBe(100.0)->and((float) $row->paid_usd)->toBe(60.0)
        ->and((int) $row->usage_count)->toBe(2)
        ->and(ProcedureClassification::financialLines($row, 'paid'))->toBe(['1,500.00 ₾', '$60.00']);
});

test('mapping changes visibility without modifying procedure charges payments or historical records', function () {
    $visit = ($this->makeVisit)([['Custom implant', 2500, 1]], attributes: ['discount_type' => 'percent', 'discount_value' => 20]);
    ($this->pay)($visit, 1500);
    $beforeItems = $visit->treatmentCaseItems()->get()->toJson();
    $beforePayments = $visit->payments()->get()->toJson();
    $catalog = TreatmentCase::create(['name' => 'Implant surgery', 'category' => 'surgery']);
    ProcedureClassification::assign('Custom implant', $catalog->id);
    expect(ProcedureClassification::uncategorized()->count())->toBe(0);
    $row = ProcedureClassification::uncategorized(includeMapped: true)->sole();
    expect((float) $row->charged_gel)->toBe(2500.0)->and((float) $row->paid_gel)->toBe(1500.0)
        ->and($visit->fresh()->net_amount)->toBe(2000.0)
        ->and($visit->treatmentCaseItems()->get()->toJson())->toBe($beforeItems)
        ->and($visit->payments()->get()->toJson())->toBe($beforePayments);
});

test('unpaid work shows zero while a separate legacy visit charge prevents exact paid attribution', function () {
    ($this->makeVisit)([['Unpaid', 100, 1], ['Unpaid second', 50, 1]]);
    $visit = ($this->makeVisit)([['Legacy work', 100, 1]], attributes: ['total_price' => 200]);
    ($this->pay)($visit, 150);
    $rows = ProcedureClassification::uncategorized()->get()->keyBy('procedure_name');
    expect(ProcedureClassification::financialLines($rows['Unpaid'], 'paid'))->toBe(['0.00 ₾'])
        ->and(ProcedureClassification::financialLines($rows['Legacy work'], 'paid'))->toBe(['— GEL']);
});
