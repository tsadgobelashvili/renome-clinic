<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-23 12:00:00');
    Http::preventStrayRequests();
    Cache::put('nbg:official-rate:USD:2026-11-23', 2.5, now()->endOfDay());
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Input',
        'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->funding = $this->patient->partnerPayments()->create(['amount' => 100000, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => today()]);
    $this->doctor = Doctor::create(['first_name' => 'Synthetic', 'last_name' => 'Doctor', 'is_active' => true,
        'compensation_percentage' => 40, 'israeli_lab_zircon_rate' => 4995]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'source' => 'israeli', 'case_date' => today()]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
});

/** Extract the actual rendered input for the native browser regression, without patient data. */
function financialAmountInputFixture($page, string $statePath): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML($page->getMountedActionModalHtml());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    foreach ($document->getElementsByTagName('input') as $input) {
        foreach ($input->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'wire:model') && $attribute->value === $statePath) {
                expect($input->getAttribute('type'))->toBe('text')
                    ->and($input->getAttribute('inputmode'))->toBe('decimal');

                return ['type' => $input->getAttribute('type'), 'inputmode' => $input->getAttribute('inputmode'),
                    'step' => $input->getAttribute('step'), 'html' => $document->saveHTML($input)];
            }
        }
    }
    throw new RuntimeException('Rendered financial amount input not found: '.$statePath);
}

test('patient create edit and Israeli salary modals render non stepping decimal inputs', function () {
    $create = Livewire::test(ListPartnerFinance::class)->mountAction('addPatientPayment');
    $edit = Livewire::test(ListPartnerFinance::class)
        ->mountAction(TestAction::make('patientPaymentDetails')->table('payment-'.$this->funding->id))->mountAction('editPayment');
    $salary = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $row = array_key_first($salary->get('mountedActions')[0]['data']['allocations']);
    $fixtures = [
        'patient-create' => financialAmountInputFixture($create, 'mountedActions.0.data.amount'),
        'patient-edit' => financialAmountInputFixture($edit, 'mountedActions.1.data.amount'),
        'doctor-salary' => financialAmountInputFixture($salary, "mountedActions.0.data.allocations.$row.amount"),
    ];
    $path = getenv('FINANCIAL_INPUT_UI_FIXTURE') ?: storage_path('framework/testing/financial-amount-inputs.json');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('patient payment text input persists the exact entered cents', function (string $amount) {
    Livewire::test(ListPartnerFinance::class)->callAction('addPatientPayment', [
        'patient_id' => $this->patient->id, 'amount' => $amount, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => today()->toDateString(),
    ])->assertHasNoActionErrors();
    $payment = PartnerPatientPayment::whereKeyNot($this->funding->id)->sole();
    expect($payment->amount)->toBe($amount)->and($payment->currency)->toBe('USD')
        ->and(number_format((float) DB::table('partner_patient_payments')->where('id', $payment->id)->value('amount'), 2, '.', ''))->toBe($amount);
})->with(['2520.00', '2520.01', '1998.00', '2000.00', '2002.00', '0.01']);

test('Israeli salary text input persists the exact entered native payout', function (string $amount) {
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $row = array_key_first($page->get('mountedActions')[0]['data']['allocations']);
    $page->set("mountedActions.0.data.allocations.$row.amount", $amount)->callMountedAction()->assertHasNoActionErrors();
    $payout = SalaryPayout::sole();
    expect($payout->allocations()->sole()->amount)->toBe($amount)->and(PartnerFinanceTransaction::sole()->amount)->toBe($amount)
        ->and(number_format((float) DB::table('salary_payout_allocations')->where('salary_payout_id', $payout->id)->value('amount'), 2, '.', ''))->toBe($amount)
        ->and($this->funding->fresh()->amount)->toBe('100000.00');
})->with(['2520.00', '2520.01', '1998.00', '2000.00', '2002.00', '0.01']);

test('text financial inputs retain numeric and minimum validation', function (string $amount, string $rule) {
    Livewire::test(ListPartnerFinance::class)->callAction('addPatientPayment', [
        'patient_id' => $this->patient->id, 'amount' => $amount, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => today()->toDateString(),
    ])->assertHasActionErrors(['amount' => $rule]);
    $salary = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $row = array_key_first($salary->get('mountedActions')[0]['data']['allocations']);
    $salary->set("mountedActions.0.data.allocations.$row.amount", $amount)->callMountedAction()
        ->assertHasActionErrors(["allocations.$row.amount" => $rule]);
    expect(PartnerPatientPayment::count())->toBe(1)->and(SalaryPayout::count())->toBe(0);
})->with([['not-money', 'numeric'], ['0', 'min']]);
