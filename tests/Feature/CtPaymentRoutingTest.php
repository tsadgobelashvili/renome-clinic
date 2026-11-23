<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PartnerFinanceSummary;
use App\Services\VisitPaymentRouting;
use App\Services\VisitPaymentSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-23 12:00:00');
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'CT', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->doctor = Doctor::create(['first_name' => 'Synthetic', 'last_name' => 'Doctor', 'is_active' => true, 'specialties' => ['therapy', 'tomography']]);
    $this->ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'default_price' => 60, 'is_active' => true]);
    $this->treatment = TreatmentCase::create(['name' => 'Israeli treatment', 'category' => 'therapy', 'default_price' => 180, 'is_active' => true]);
    $this->makeVisit = function (float $ct = 120, float $other = 0, float $discount = 0): Visit {
        $visit = Visit::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(),
            'visit_type' => 'treatment', 'currency' => 'GEL', 'total_price' => $ct + $other, 'discount_type' => 'amount', 'discount_value' => $discount]);
        foreach ([[$this->ct, $ct], [$this->treatment, $other]] as [$service, $value]) {
            if ($value > 0) {
                $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => $value, 'currency' => 'GEL']);
            }
        }

        return $visit->fresh();
    };
    $this->pay = function (Visit $visit, float $amount, array $extra = []): Visit {
        $data = array_replace(['visit_id' => $visit->id, 'payment_request_key' => (string) Str::uuid(), 'amount' => $amount,
            'splits' => [['amount' => $amount, 'currency' => 'GEL', 'payment_method' => 'cash']]], $extra);

        return app(VisitPaymentSubmission::class)->run($visit->patient_id, $data, function ($submission) use ($visit, $data) {
            app(VisitPaymentRouting::class)->postCombined($visit, $data, $submission);

            return $visit->fresh();
        });
    };
});

test('Dashboard standalone CT for an Israeli patient posts only one Clinic cash receipt', function () {
    Livewire::test(Dashboard::class)->callAction('manageTomography', [
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'currency' => 'GEL',
        'tomographyItems' => [['treatment_case_id' => $this->ct->id, 'quantity' => 2, 'unit_price' => 60]],
        'paymentSplits' => [['payment_method' => 'cash', 'amount' => 45, 'currency' => 'USD', 'exchange_rate' => 2.666667]],
    ])->assertHasNoActionErrors();
    expect(Payment::count())->toBe(1)->and(PartnerPatientPayment::count())->toBe(0)
        ->and((float) CashboxTransaction::sole()->amount)->toBe(45.0)->and(CashboxTransaction::sole()->currency)->toBe('USD')
        ->and(Visit::sole()->paid_amount)->toBe(120.0)->and(Visit::sole()->remaining_amount)->toBe(0.0)
        ->and(app(PartnerFinanceSummary::class)->receivedTotals()['USD'])->toBe(0.0);
});

test('Dashboard New Visit CT confirmation is replayable without creating another visit or payment', function () {
    $data = ['payment_request_key' => (string) Str::uuid(), 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'visit_date' => today()->toDateString(), 'visit_type' => 'treatment', 'discount_percent' => 0,
        'treatmentCaseItems' => [['treatment_case_id' => $this->ct->id, 'quantity' => 2, 'unit_price' => 60]],
        'paymentSplits' => [['amount' => 120, 'currency' => 'GEL', 'payment_method' => 'cash']]];
    Livewire::test(Dashboard::class)->callAction('newVisit', $data)->assertHasNoActionErrors();
    Livewire::test(Dashboard::class)->callAction('newVisit', $data)->assertHasNoActionErrors();
    expect(Visit::count())->toBe(1)->and(Payment::count())->toBe(1)->and(CashboxTransaction::count())->toBe(1)
        ->and(PartnerPatientPayment::count())->toBe(0)->and(DB::table('visit_payment_submissions')->count())->toBe(1);
});

test('resource creation and EditVisit route Israeli CT through the Clinic processor', function () {
    $data = ['payment_request_key' => (string) Str::uuid(), 'amount' => 50, 'currency' => 'GEL',
        'splits' => [['amount' => 50, 'currency' => 'GEL', 'payment_method' => 'cash']]];
    Livewire::test(CreateVisit::class)->fillForm(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'visit_date' => today()->toDateString(), 'visit_type' => 'treatment', 'currency' => 'GEL', 'discount_type' => 'amount', 'discount_value' => 0,
        'treatmentCaseItems' => [['treatment_case_id' => $this->ct->id, 'quantity' => 2, 'unit_price' => 60, 'currency' => 'GEL']]])
        ->call('submitPaymentAndCreate', $data)->assertHasNoErrors();
    $visit = Visit::sole();
    $data['payment_request_key'] = (string) Str::uuid();
    $edit = Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()]);
    $edit->call('submitPayment', $data)->assertHasNoErrors()->call('submitPayment', $data)->assertHasNoErrors();
    expect(Payment::count())->toBe(2)->and((float) Payment::sum('amount'))->toBe(100.0)
        ->and(CashboxTransaction::count())->toBe(2)->and(PartnerPatientPayment::count())->toBe(0)
        ->and($visit->fresh()->remaining_amount)->toBe(20.0);
});

test('explicit mixed partial allocations reach the selected ledgers exactly once', function () {
    $visit = ($this->makeVisit)(120, 180);
    $key = (string) Str::uuid();
    ($this->pay)($visit, 100, ['payment_request_key' => $key, 'ct_payment_amount' => 70, 'treatment_payment_amount' => 30]);
    ($this->pay)($visit, 100, ['payment_request_key' => $key, 'ct_payment_amount' => 70, 'treatment_payment_amount' => 30]);
    expect($visit->fresh()->paid_amount)->toBe(100.0)->and($visit->fresh()->remaining_amount)->toBe(200.0);
    ($this->pay)($visit, 200, ['ct_payment_amount' => 50, 'treatment_payment_amount' => 150]);
    expect((float) Payment::sum('amount'))->toBe(120.0)->and((float) PartnerPatientPayment::sum('amount'))->toBe(180.0)
        ->and((float) CashboxTransaction::sum('amount'))->toBe(120.0)->and(FinanceTransaction::count())->toBe(0)
        ->and($visit->fresh()->paid_amount)->toBe(300.0)->and($visit->fresh()->remaining_amount)->toBe(0.0)
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals()['GEL'])->toBe(180.0);
});

test('missing mismatched or excessive mixed allocations roll back both ledgers', function (array $allocation) {
    $visit = ($this->makeVisit)(120, 180);
    expect(fn () => ($this->pay)($visit, 150, $allocation))->toThrow(ValidationException::class);
    expect(Payment::count())->toBe(0)->and(PartnerPatientPayment::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0)
        ->and(DB::table('visit_payment_submissions')->count())->toBe(0)->and(DB::table('visit_payment_allocations')->count())->toBe(0);
})->with(['missing' => [[]], 'mismatch' => [['ct_payment_amount' => 50, 'treatment_payment_amount' => 50]],
    'CT overpayment' => [['ct_payment_amount' => 130, 'treatment_payment_amount' => 20]]]);

test('a changed payload cannot reuse a confirmation and a fresh key permits another partial payment', function () {
    $visit = ($this->makeVisit)();
    $key = (string) Str::uuid();
    ($this->pay)($visit, 30, ['payment_request_key' => $key]);
    expect(fn () => ($this->pay)($visit, 40, ['payment_request_key' => $key]))->toThrow(ValidationException::class);
    ($this->pay)($visit, 30);
    expect(Payment::count())->toBe(2)->and($visit->fresh()->paid_amount)->toBe(60.0)->and($visit->fresh()->remaining_amount)->toBe(60.0);
});

test('mixed discounts require an explicit CT discount share and preserve exact native currencies', function () {
    $visit = ($this->makeVisit)(120, 180, 30);
    $data = ['ct_payment_amount' => 100, 'treatment_payment_amount' => 170,
        'splits' => [['amount' => 108, 'currency' => 'USD', 'exchange_rate' => 2.5, 'payment_method' => 'cash']]];
    expect(fn () => ($this->pay)($visit, 270, $data))->toThrow(ValidationException::class);
    ($this->pay)($visit, 270, [...$data, 'ct_discount_amount' => 20]);
    expect((float) CashboxTransaction::sole()->amount)->toBe(40.0)->and((float) PartnerPatientPayment::sole()->amount)->toBe(68.0)
        ->and($visit->fresh()->paid_amount)->toBe(270.0)->and($visit->fresh()->net_amount)->toBe(270.0);
});

test('genuine non CT Israeli treatment retains Israeli Finance routing with request idempotency', function () {
    $visit = ($this->makeVisit)(0, 180);
    $key = (string) Str::uuid();
    ($this->pay)($visit, 90, ['payment_request_key' => $key]);
    ($this->pay)($visit, 90, ['payment_request_key' => $key]);
    expect(PartnerPatientPayment::count())->toBe(1)->and((float) PartnerPatientPayment::sole()->amount)->toBe(90.0)
        ->and(Payment::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('ambiguous historical Israeli CT receipts are preserved and cannot provoke another automatic payment', function () {
    $visit = ($this->makeVisit)();
    foreach ([118, 119] as $id) {
        DB::table('partner_patient_payments')->insert(['id' => $id, 'patient_id' => $this->patient->id, 'visit_id' => $visit->id,
            'amount' => 45, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => '2026-11-18',
            'notes' => 'Visit payment', 'created_at' => '2026-11-18', 'updated_at' => '2026-11-18']);
    }
    $before = DB::table('partner_patient_payments')->orderBy('id')->get()->toArray();
    expect(fn () => ($this->pay)($visit, 120))->toThrow(ValidationException::class);
    expect(DB::table('partner_patient_payments')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(Payment::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('mixed Dashboard payments require visible explicit allocations and never default to a proportional split', function () {
    $page = Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm([
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(),
        'visit_type' => 'treatment', 'treatmentCaseItems' => [
            ['treatment_case_id' => $this->ct->id, 'quantity' => 2, 'unit_price' => 60],
            ['treatment_case_id' => $this->treatment->id, 'quantity' => 1, 'unit_price' => 180],
        ], 'paymentSplits' => [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']],
    ])->assertFormFieldIsVisible('ct_payment_amount')->assertFormFieldIsVisible('treatment_payment_amount')
        ->callMountedAction()->assertHasActionErrors(['ct_payment_amount' => 'required', 'treatment_payment_amount' => 'required']);
    expect(Visit::count())->toBe(0)->and(Payment::count())->toBe(0)->and(PartnerPatientPayment::count())->toBe(0);
    $page->fillForm(['ct_payment_amount' => 80, 'treatment_payment_amount' => 20])->callMountedAction()->assertHasNoActionErrors();
    expect((float) Payment::sole()->amount)->toBe(80.0)->and((float) PartnerPatientPayment::sole()->amount)->toBe(20.0);
});

test('combined service and retail payment remains atomic and replay cannot duplicate either leg', function () {
    $visit = ($this->makeVisit)(120, 180);
    $product = Product::create(['name' => 'Synthetic retail item', 'selling_price' => 20, 'is_active' => true, 'catalog_status' => 'sellable']);
    $data = ['payment_request_key' => (string) Str::uuid(), 'ct_payment_amount' => 60, 'treatment_payment_amount' => 40,
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]];
    ($this->pay)($visit, 120, $data);
    ($this->pay)($visit, 120, $data);
    expect(ProductSale::count())->toBe(1)->and((float) ProductSale::sole()->total)->toBe(20.0)
        ->and((float) Payment::sole()->amount)->toBe(60.0)->and((float) PartnerPatientPayment::sole()->amount)->toBe(40.0)
        ->and((float) CashboxTransaction::sum('amount'))->toBe(80.0);
});

test('a failure after the CT leg rolls back Clinic cash Israeli receipts and the request claim', function () {
    $visit = ($this->makeVisit)(120, 180);
    $event = 'eloquent.creating: '.PartnerPatientPayment::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic posting failure'));
    try {
        expect(fn () => ($this->pay)($visit, 100, ['ct_payment_amount' => 70, 'treatment_payment_amount' => 30]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(Payment::count())->toBe(0)->and(PartnerPatientPayment::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0)
        ->and(DB::table('visit_payment_submissions')->count())->toBe(0)->and(DB::table('visit_payment_allocations')->count())->toBe(0);
});

test('mixed unpaid visits can be created without pretending that a payment was allocated', function () {
    Livewire::test(Dashboard::class)->callAction('newVisit', [
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(),
        'visit_type' => 'treatment', 'treatmentCaseItems' => [
            ['treatment_case_id' => $this->ct->id, 'quantity' => 2, 'unit_price' => 60],
            ['treatment_case_id' => $this->treatment->id, 'quantity' => 1, 'unit_price' => 180],
        ], 'paymentSplits' => [],
    ])->assertHasNoActionErrors();
    expect(Visit::count())->toBe(1)->and(Payment::count())->toBe(0)->and(PartnerPatientPayment::count())->toBe(0)
        ->and(Visit::sole()->remaining_amount)->toBe(300.0);
});
