<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('discount reason is required and visible only for a full percentage discount', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Reason', 'last_name' => 'Patient']);
    $service = TreatmentCase::create(['name' => 'Reason service', 'category' => 'therapy', 'is_active' => true]);
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'visit_type' => 'treatment',
        'discount_type' => 'percent', 'discount_value' => 20,
        'treatmentCaseItems' => [[
            'service_choice' => (string) $service->getKey(), 'treatment_case_id' => $service->getKey(),
            'quantity' => 1, 'unit_price' => 1000,
        ]],
    ]);

    $component->assertDontSee('ფასდაკლების მიზეზი')
        ->set('data.discount_value', 100)
        ->assertSee('ფასდაკლების მიზეზი')
        ->call('create')
        ->assertHasFormErrors(['discount_reason' => 'required']);

    $component->set('data.discount_reason', 'other')
        ->assertSee('მიზეზის აღწერა')
        ->call('create')
        ->assertHasFormErrors(['discount_comment' => 'required']);
});

test('new visit payment confirms atomically with discounts and redirects to dashboard', function (
    float $discountValue,
    float $expectedDiscount,
    float $expectedPayable,
    array $splits,
    ?string $reason,
) {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Paid', 'last_name' => 'Visit']);
    $doctor = Doctor::create(['first_name' => 'Visit', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create(['name' => 'Payment flow service', 'category' => 'therapy', 'is_active' => true]);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'treatment',
            'discount_type' => 'percent',
            'discount_value' => $discountValue,
            'discount_reason' => $reason,
            'discount_comment' => $reason === 'other' ? 'Approved exception' : null,
            'treatmentCaseItems' => [[
                'service_choice' => (string) $service->getKey(),
                'treatment_case_id' => $service->getKey(),
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'service_amount' => $expectedPayable,
            'amount' => $expectedPayable,
            'currency' => 'GEL',
            'products' => [],
            'splits' => $splits,
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect(Dashboard::getUrl());

    $visit = Visit::query()->with('treatmentCaseItems', 'payments.splits')->sole();
    expect((float) $visit->total_price)->toBe(1000.0)
        ->and($visit->discount_type)->toBe('percent')
        ->and((float) $visit->discount_value)->toBe($discountValue)
        ->and((float) $visit->discount_amount)->toBe($expectedDiscount)
        ->and($visit->net_amount)->toBe($expectedPayable)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and($visit->discount_reason)->toBe($reason)
        ->and($visit->treatmentCaseItems)->toHaveCount(1)
        ->and(Visit::query()->count())->toBe(1);

    if ($expectedPayable === 0.0) {
        expect($visit->payment_status)->toBe('free')
            ->and(Payment::query()->count())->toBe(0)
            ->and(CashboxTransaction::query()->count())->toBe(0);
    } else {
        $payment = $visit->payments->sole();
        expect((float) $payment->amount)->toBe($expectedPayable)
            ->and($payment->splits)->toHaveCount(count($splits))
            ->and((float) $payment->splits->sum('amount'))->toBe($expectedPayable)
            ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->count())->toBe(count($splits));
    }
})->with([
    'normal percentage discount' => [20, 200, 800, [['payment_method' => 'card', 'amount' => 800, 'currency' => 'GEL']], null],
    'free employee benefit' => [100, 1000, 0, [], 'employee'],
    'discounted split payment' => [10, 100, 900, [
        ['payment_method' => 'cash', 'amount' => 400, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 500, 'currency' => 'GEL'],
    ], null],
]);
