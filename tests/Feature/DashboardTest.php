<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard renders its content without the visible dashboard heading', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertDontSeeHtml('class="fi-header-heading"')
        ->assertSee('სალარო')
        ->assertSee('ტომოგრაფია')
        ->assertSee('ახალი ვიზიტი')
        ->assertSee('No visits')
        ->assertSeeHtml('renome-date-range-calendar')
        ->assertDontSeeHtml('type="date"')
        ->assertActionExists('cashboxOverview')
        ->assertActionExists('manageTomography');
});

test('dashboard visit rows render quantity chips and compact financial columns', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Compact', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Compact', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create([
        'name' => 'Dashboard Service',
        'category' => 'therapy',
        'default_price' => 50,
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => 100,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(),
        'quantity' => 2,
        'unit_price' => 50,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visit])
        ->assertSee('Dashboard Service')
        ->assertSee('x2')
        ->assertSeeHtml('renome-treatment-service');
});

test('dashboard defaults to todays visits while allowing an older date range', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Daily', 'last_name' => 'Filter']);
    $doctor = Doctor::create(['first_name' => 'Daily', 'last_name' => 'Doctor', 'is_active' => true]);
    $todayVisit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'total_price' => 100,
    ]);
    $olderVisit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today()->subDay(), 'total_price' => 100,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSet('tableFilters.visit_date.from', fn ($date): bool => Carbon::parse($date)->isToday())
        ->assertSet('tableFilters.visit_date.until', fn ($date): bool => Carbon::parse($date)->isToday())
        ->assertCanSeeTableRecords([$todayVisit])
        ->assertCanNotSeeTableRecords([$olderVisit])
        ->set('tableFilters.visit_date.from', today()->subDay()->toDateString())
        ->assertCanSeeTableRecords([$todayVisit, $olderVisit]);
});

test('dashboard paid column preserves actual gel usd and mixed payment currencies', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Currency', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Currency', 'last_name' => 'Doctor', 'is_active' => true]);

    $makeVisit = fn (float $total): Visit => Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => $total,
    ]);

    $usdVisit = $makeVisit(120);
    app(PaymentProcessor::class)->process([
        'visit_id' => $usdVisit->getKey(), 'amount' => 120, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 45, 'currency' => 'USD', 'exchange_rate' => 2.625]]);

    $gelVisit = $makeVisit(80);
    app(PaymentProcessor::class)->process([
        'visit_id' => $gelVisit->getKey(), 'amount' => 80, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 80, 'currency' => 'GEL']]);

    $mixedVisit = $makeVisit(154);
    app(PaymentProcessor::class)->process([
        'visit_id' => $mixedVisit->getKey(), 'amount' => 154, 'currency' => 'GEL', 'payment_date' => today(),
    ], [
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 20, 'currency' => 'USD', 'exchange_rate' => 2.7],
    ]);

    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertSee('$45.00')
        ->assertSee('80.00 ₾')
        ->assertSee('100.00 ₾')
        ->assertSee('$20.00')
        ->assertSee('0.00 ₾')
        ->assertSeeHtml('aria-label="ნაღდი"')
        ->assertSeeHtml('aria-label="ბარათი"')
        ->assertSeeHtml('renome-financial-header')
        ->assertSeeHtml('renome-financial-cell');

    expect($usdVisit->fresh()->remaining_amount)->toBe(0.0);
});

test('tomography summary uses actual received amounts in their original currencies', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Tomography', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Tomography', 'last_name' => 'Doctor', 'is_active' => true]);
    $tomography = TreatmentCase::create([
        'name' => 'Dashboard CT',
        'category' => 'tomography',
        'default_price' => 120,
        'is_active' => true,
    ]);

    $makeVisit = function () use ($patient, $doctor, $tomography): Visit {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today(),
            'currency' => 'GEL',
            'total_price' => 120,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $tomography->getKey(),
            'quantity' => 1,
            'unit_price' => 120,
        ]);

        return $visit;
    };

    $gelVisit = $makeVisit();
    app(PaymentProcessor::class)->process([
        'visit_id' => $gelVisit->getKey(), 'amount' => 120, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 120, 'currency' => 'GEL']]);

    $usdVisit = $makeVisit();
    app(PaymentProcessor::class)->process([
        'visit_id' => $usdVisit->getKey(), 'amount' => 120, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'card', 'amount' => 45, 'currency' => 'USD', 'exchange_rate' => 2.625]]);

    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertSee('მიღებული: 120.00 ₾ + $45.00')
        ->assertSeeHtml('aria-label="ნაღდი"')
        ->assertSeeHtml('aria-label="ბარათი"');
});

test('dashboard visit row opens a read only details modal and keeps editing intentional', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Detail', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Detail', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create([
        'name' => 'Detailed Service',
        'category' => 'therapy',
        'default_price' => 40,
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => 120,
        'diagnosis' => 'Focused diagnosis',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(),
        'quantity' => 3,
        'unit_price' => 40,
        'teeth' => '11, 12',
    ]);
    app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 120, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'card', 'amount' => 45, 'currency' => 'USD', 'exchange_rate' => 2.625]]);

    $dashboard = Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertTableActionExists('visitDetails', record: $visit)
        ->mountTableAction('visitDetails', $visit)
        ->assertMountedActionModalSee([
            'ვიზიტის დეტალები',
            'Detail Patient',
            'Detail Doctor',
            'Detailed Service',
            '11, 12',
            '$45.00',
            'Focused diagnosis',
        ])
        ->assertSeeHtml('aria-label="ბარათი"');

    $editAction = $dashboard->instance()->getMountedAction()?->getModalSubmitAction();

    expect($dashboard->instance()->getTable()->getRecordUrl($visit))->toBeNull()
        ->and($dashboard->instance()->getTable()->getRecordAction($visit))->toBe('visitDetails')
        ->and($editAction?->getLabel())->toBe('რედაქტირება')
        ->and($editAction?->getUrl())->toContain('/admin/visits/'.$visit->getKey().'/edit?return=dashboard');

    $dashboard->unmountTableAction()
        ->assertCanSeeTableRecords([$visit]);
});

test('operational dashboard shows centralized cashbox and todays tomography summary', function () {
    $this->actingAs(User::factory()->create());
    $cashbox = app(CashboxManager::class);
    $previous = $cashbox->dayFor(today()->subDay()->toDateString());
    $previous->update(['opening_balance' => 250, 'opening_balance_usd' => 40]);
    $cashbox->close($previous, 250, 0, null, 40, 0);
    $cashbox->addOpeningBalance($cashbox->today(), 250, 40);

    $patient = Patient::create(['first_name' => 'Dashboard', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Dashboard', 'last_name' => 'Doctor', 'is_active' => true]);
    $tomography = TreatmentCase::create([
        'name' => 'Dashboard 3D CT',
        'category' => 'tomography',
        'default_price' => 60,
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
        'currency' => 'GEL',
        'total_price' => 120,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $tomography->getKey(),
        'quantity' => 2,
        'unit_price' => 60,
    ]);
    app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 120,
        'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'card', 'amount' => 120, 'currency' => 'GEL']]);

    $dashboard = Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertSeeHtml('renome-dashboard-summary-card')
        ->assertSee('სალარო')
        ->assertSee('250.00 ₾')
        ->assertSee('$40.00')
        ->assertSee('ტომოგრაფია')
        ->assertSee('დღეს: 1')
        ->assertSee('120.00 ₾')
        ->assertSee('Dashboard 3D CT')
        ->assertSeeHtml('renome-treatment-service')
        ->assertSee('x2')
        ->assertCanSeeTableRecords([$visit]);

    expect($dashboard->instance()->getHeading())->toBeNull()
        ->and($dashboard->instance()->getExtraBodyAttributes())->toMatchArray([
            'class' => 'renome-dashboard-body',
        ]);

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->assertMountedActionModalSee([
            'ქეშის დამატება',
            'საწყისი ნაშთი',
            'ხარჯი',
            'პროდუქტის გაყიდვა',
        ]);
});

test('dashboard visit create context returns to dashboard and tomography context reuses visit form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['return' => 'dashboard', 'tomography' => 1])
        ->test(CreateVisit::class)
        ->assertSet('returnToDashboard', true)
        ->assertFormSet(['visit_type' => 'consultation']);
});

test('dashboard new visit modal creates services and mixed payments once without redirecting', function () {
    Http::fake([
        '*' => Http::response('<?xml version="1.0"?><Envelope><CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.700000</Rate></CurrencyRate></Envelope>'),
    ]);
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Modal', 'last_name' => 'Visit']);
    $doctor = Doctor::create(['first_name' => 'Modal', 'last_name' => 'Doctor', 'is_active' => true]);
    $first = TreatmentCase::create(['name' => 'First work', 'category' => 'therapy', 'default_price' => 100, 'is_active' => true]);
    $second = TreatmentCase::create(['name' => 'Second work', 'category' => 'consultation', 'default_price' => 54, 'is_active' => true]);

    Livewire::test(Dashboard::class)
        ->assertActionExists('newVisit')
        ->mountAction('newVisit')
        ->assertMountedActionModalSee(['ვიზიტის ინფორმაცია', 'შესრულებული სამუშაო', 'გადახდა'])
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->toDateString(),
            'treatmentCaseItems' => [
                ['treatment_case_id' => $first->getKey(), 'quantity' => 1, 'unit_price' => 100],
                ['treatment_case_id' => $second->getKey(), 'quantity' => 1, 'unit_price' => 54],
            ],
            'paymentSplits' => [
                ['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 100],
                ['payment_method' => 'card', 'currency' => 'USD', 'amount' => 20, 'exchange_rate' => 2.7],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionNotMounted();

    $visit = Visit::query()->sole();
    expect($visit->treatmentCaseItems()->count())->toBe(2)
        ->and($visit->payments()->count())->toBe(1)
        ->and($visit->payments()->sole()->splits()->count())->toBe(2)
        ->and((float) $visit->total_price)->toBe(154.0);
});

test('dashboard new visit starts with one cash gel payment and syncs it until manually changed', function () {
    $this->actingAs(User::factory()->create());
    $service = TreatmentCase::create([
        'name' => 'Synced work', 'category' => 'therapy', 'default_price' => 120, 'is_active' => true,
    ]);

    $component = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $actionIndex = array_key_last($component->get('mountedActions'));
    $paymentStatePath = "mountedActions.{$actionIndex}.data.paymentSplits";
    $itemStatePath = "mountedActions.{$actionIndex}.data.treatmentCaseItems";

    $payments = array_values($component->get($paymentStatePath));
    expect($payments)->toHaveCount(1)
        ->and($payments[0]['payment_method'])->toBe('cash')
        ->and($payments[0]['currency'])->toBe('GEL');

    $items = $component->get($itemStatePath);
    $itemKey = array_key_first($items);
    $component
        ->set("{$itemStatePath}.{$itemKey}.treatment_case_id", $service->getKey())
        ->set("{$itemStatePath}.{$itemKey}.quantity", 2)
        ->set("{$itemStatePath}.{$itemKey}.unit_price", 120);

    $payments = array_values($component->get($paymentStatePath));
    expect((float) $payments[0]['amount'])->toBe(240.0);

    $paymentKey = array_key_first($component->get($paymentStatePath));
    $component
        ->set("{$paymentStatePath}.{$paymentKey}.amount", 100)
        ->set("{$itemStatePath}.{$itemKey}.quantity", 3);

    $payments = array_values($component->get($paymentStatePath));
    expect((float) $payments[0]['amount'])->toBe(100.0);

    $splitComponent = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $splitActionIndex = array_key_last($splitComponent->get('mountedActions'));
    $splitItemsPath = "mountedActions.{$splitActionIndex}.data.treatmentCaseItems";
    $splitPaymentsPath = "mountedActions.{$splitActionIndex}.data.paymentSplits";
    $splitItemKey = array_key_first($splitComponent->get($splitItemsPath));
    $splitComponent
        ->set("{$splitItemsPath}.{$splitItemKey}.treatment_case_id", $service->getKey())
        ->set("{$splitItemsPath}.{$splitItemKey}.quantity", 1)
        ->set("{$splitItemsPath}.{$splitItemKey}.unit_price", 120)
        ->set($splitPaymentsPath, [
            ['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 70],
            ['payment_method' => 'card', 'currency' => 'GEL', 'amount' => 50],
        ])
        ->set("{$splitItemsPath}.{$splitItemKey}.quantity", 2);

    expect(collect($splitComponent->get($splitPaymentsPath))
        ->pluck('amount')->map(fn ($amount): float => (float) $amount)->values()->all())
        ->toBe([70.0, 50.0]);
});

test('dashboard new visit manipulation field accepts catalog and historical or new manual names', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Autocomplete', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Autocomplete', 'last_name' => 'Doctor', 'is_active' => true]);
    $catalog = TreatmentCase::create([
        'name' => 'Catalog implantation', 'category' => 'surgery', 'default_price' => 750, 'is_active' => true,
    ]);
    $historyVisit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today()->subDay(), 'total_price' => 325,
    ]);
    $historyVisit->treatmentCaseItems()->create([
        'custom_service_name' => 'Historical custom work', 'quantity' => 1, 'unit_price' => 325,
    ]);

    $catalogComponent = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $catalogActionIndex = array_key_last($catalogComponent->get('mountedActions'));
    $catalogItemsPath = "mountedActions.{$catalogActionIndex}.data.treatmentCaseItems";
    $catalogItemKey = array_key_first($catalogComponent->get($catalogItemsPath));
    $catalogComponent
        ->set("{$catalogItemsPath}.{$catalogItemKey}.manipulation_name", 'Catalog')
        ->set("{$catalogItemsPath}.{$catalogItemKey}.manipulation_name", 'Catalog implantation — 750.00 ₾');
    $catalogState = $catalogComponent->get("{$catalogItemsPath}.{$catalogItemKey}");

    expect((int) $catalogState['treatment_case_id'])->toBe($catalog->getKey())
        ->and($catalogState['custom_service_name'])->toBeNull()
        ->and((float) $catalogState['unit_price'])->toBe(750.0);

    $manualComponent = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $manualActionIndex = array_key_last($manualComponent->get('mountedActions'));
    $manualItemsPath = "mountedActions.{$manualActionIndex}.data.treatmentCaseItems";
    $manualItemKey = array_key_first($manualComponent->get($manualItemsPath));
    $manualComponent
        ->set("{$manualItemsPath}.{$manualItemKey}.manipulation_name", 'Historical')
        ->set("{$manualItemsPath}.{$manualItemKey}.manipulation_name", 'Historical custom work — 325.00 ₾')
        ->assertSet("{$manualItemsPath}.{$manualItemKey}.unit_price", 325)
        ->set("{$manualItemsPath}.{$manualItemKey}.manipulation_name", 'Brand new manual work')
        ->set("{$manualItemsPath}.{$manualItemKey}.unit_price", 410)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->toDateString(),
            'paymentSplits' => [],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $createdVisit = Visit::query()->latest('id')->firstOrFail();
    $createdItem = $createdVisit->treatmentCaseItems()->sole();
    expect($createdItem->treatment_case_id)->toBeNull()
        ->and($createdItem->custom_service_name)->toBe('Brand new manual work')
        ->and((float) $createdItem->unit_price)->toBe(410.0)
        ->and(TreatmentCase::query()->where('name', 'Brand new manual work')->exists())->toBeFalse();
});

test('dashboard new visit synchronizes percentage and gel discounts with the payable payment', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Discount', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Discount', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create([
        'name' => 'Discounted work', 'category' => 'therapy', 'default_price' => 500, 'is_active' => true,
    ]);

    $component = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $actionIndex = array_key_last($component->get('mountedActions'));
    $dataPath = "mountedActions.{$actionIndex}.data";
    $itemsPath = "{$dataPath}.treatmentCaseItems";
    $itemKey = array_key_first($component->get($itemsPath));
    $paymentsPath = "{$dataPath}.paymentSplits";

    $component
        ->set("{$itemsPath}.{$itemKey}.treatment_case_id", $service->getKey())
        ->set("{$itemsPath}.{$itemKey}.quantity", 1)
        ->set("{$itemsPath}.{$itemKey}.unit_price", 500)
        ->set("{$dataPath}.discount_percent", 10)
        ->assertSet("{$dataPath}.discount_amount", 50);

    expect((float) collect($component->get($paymentsPath))->first()['amount'])->toBe(450.0);

    $component
        ->set("{$dataPath}.discount_amount", 100)
        ->assertSet("{$dataPath}.discount_percent", 20)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->toDateString(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    expect($visit->discount_type)->toBe('amount')
        ->and((float) $visit->discount_value)->toBe(100.0)
        ->and((float) $visit->discount_amount)->toBe(100.0)
        ->and($visit->net_amount)->toBe(400.0)
        ->and($visit->paid_amount)->toBe(400.0)
        ->and($visit->remaining_amount)->toBe(0.0);
});

test('dashboard full discount requires a reason and creates no payment revenue', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Free', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Free', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create([
        'name' => 'Complimentary work', 'category' => 'therapy', 'default_price' => 500, 'is_active' => true,
    ]);
    $form = [
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today()->toDateString(),
        'treatmentCaseItems' => [[
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 500,
        ]],
        'discount_percent' => 100,
    ];

    Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm($form)
        ->callMountedAction()
        ->assertHasActionErrors(['discount_reason']);

    expect(Visit::query()->count())->toBe(0);

    Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm($form + [
        'discount_amount' => 500,
        'discount_reason' => 'management',
        'paymentSplits' => [],
    ])->callMountedAction()->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    expect($visit->discount_type)->toBe('percent')
        ->and((float) $visit->discount_value)->toBe(100.0)
        ->and((float) $visit->discount_amount)->toBe(500.0)
        ->and($visit->net_amount)->toBe(0.0)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and($visit->payments()->exists())->toBeFalse();
});

test('dashboard new visit modal allows a service without payment', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Unpaid', 'last_name' => 'Visit']);
    $doctor = Doctor::create(['first_name' => 'Unpaid', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create(['name' => 'Unpaid work', 'category' => 'therapy', 'default_price' => 80, 'is_active' => true]);

    $component = Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today()->toDateString(),
        'treatmentCaseItems' => [['treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 80]],
        'paymentSplits' => [],
    ])->callMountedAction()->assertHasNoActionErrors()->assertActionNotMounted();

    expect(Visit::query()->sole()->payments()->count())->toBe(0);
});

test('dashboard new visit modal exposes payment validation and rolls the visit back', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Invalid', 'last_name' => 'Payment']);
    $doctor = Doctor::create(['first_name' => 'Rollback', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create(['name' => 'Rollback work', 'category' => 'therapy', 'default_price' => 100, 'is_active' => true]);

    $component = Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today()->toDateString(),
        'treatmentCaseItems' => [['treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100]],
        'paymentSplits' => [
            ['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 60],
            ['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 40],
        ],
    ])->callMountedAction();

    $component->assertHasErrors(['paymentSplits'])->assertActionMounted('newVisit');

    expect(Visit::query()->count())->toBe(0);
});

test('dashboard new visit modal preserves partial and usd ct payments', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'CT', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'CT', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'default_price' => 120, 'is_active' => true]);
    $base = [
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today()->toDateString(),
        'treatmentCaseItems' => [['treatment_case_id' => $ct->getKey(), 'quantity' => 1, 'unit_price' => 120]],
    ];

    Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm($base + [
        'paymentSplits' => [['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 40]],
    ])->callMountedAction()->assertHasNoActionErrors();

    Livewire::test(Dashboard::class)->mountAction('newVisit')->fillForm($base + [
        'paymentSplits' => [['payment_method' => 'card', 'currency' => 'USD', 'amount' => 45, 'exchange_rate' => 2.625]],
    ])->callMountedAction()->assertHasNoActionErrors();

    $visits = Visit::query()->oldest('id')->get();
    expect($visits)->toHaveCount(2)
        ->and($visits[0]->remaining_amount)->toBe(80.0)
        ->and($visits[1]->remaining_amount)->toBe(0.0)
        ->and((float) $visits[1]->payments()->sole()->splits()->sole()->amount)->toBe(45.0)
        ->and($visits[1]->payments()->sole()->splits()->sole()->currency)->toBe('USD');
});

test('dashboard new visit consultation charges through a manipulation and saves once', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Consultation', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Consultation', 'last_name' => 'Doctor', 'is_active' => true]);
    $consultation = TreatmentCase::create([
        'name' => 'Consultation service', 'category' => 'consultation', 'default_price' => 100, 'is_active' => true,
    ]);

    $modal = Livewire::test(Dashboard::class)
        ->mountAction('newVisit')
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->toDateString(),
            'visit_type' => 'consultation',
            'treatmentCaseItems' => [[
                'treatment_case_id' => $consultation->getKey(),
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            'paymentSplits' => [[
                'payment_method' => 'cash',
                'currency' => 'GEL',
                'amount' => 100,
            ]],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    expect($visit->visit_type)->toBe('consultation')
        ->and((float) $visit->consultation_fee)->toBe(0.0)
        ->and((float) $visit->total_price)->toBe(100.0)
        ->and($visit->treatmentCaseItems()->count())->toBe(1)
        ->and($visit->payments()->count())->toBe(1)
        ->and($visit->payments()->sole()->splits()->count())->toBe(1);
});

test('dashboard consultation shows its existing plan summary and visit row plan shortcut', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Plan', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Plan', 'last_name' => 'Doctor', 'is_active' => true]);
    $estimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_id' => null,
        'estimate_date' => today(),
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
        'consultation_fee' => 0,
        'total_price' => 0,
    ]);

    $modal = Livewire::test(Dashboard::class)
        ->mountAction('newVisit')
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'consultation',
        ])
        ->assertMountedActionModalSee(['გეგმის ნახვა', 'გეგმის რედაქტირება']);

    $modal->call('editDashboardTreatmentPlan', $estimate->getKey())
        ->assertActionDataSet([
            'mode' => 'edit',
            'selected_estimate_id' => $estimate->getKey(),
        ])
        ->assertNoRedirect();

    Livewire::test(Dashboard::class)
        ->assertTableActionExists('treatmentPlan', record: $visit)
        ->assertTableActionVisible('treatmentPlan', record: $visit);

    expect($patient->fresh()->latestTreatmentEstimate?->is($estimate))->toBeTrue();
});

test('dashboard consultation creates a patient plan through the shared estimate action', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'New Plan', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'New Plan', 'last_name' => 'Doctor', 'is_active' => true]);

    Livewire::test(Dashboard::class)
        ->mountAction('newVisit')
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'consultation',
        ])
        ->mountAction(TestAction::make('createEstimate')->schemaComponent())
        ->assertActionDataSet([
            'mode' => 'create',
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
        ])
        ->setActionData([
            'estimate_date' => today()->toDateString(),
            'options' => [[
                'name' => 'Variant 1',
                'stages' => [[
                    'name' => 'Stage 1',
                    'sort_order' => 1,
                    'items' => [[
                        'description' => 'Planned treatment',
                        'quantity' => 1,
                        'unit_price' => 250,
                    ]],
                ]],
            ]],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNoRedirect()
        ->assertMountedActionModalSee(['გეგმის ნახვა', 'გეგმის რედაქტირება'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $estimate = TreatmentEstimate::query()->sole();
    $visit = Visit::query()->sole();
    expect($estimate->patient_id)->toBe($patient->getKey())
        ->and($estimate->doctor_id)->toBe($doctor->getKey())
        ->and($estimate->visit_id)->toBe($visit->getKey());
});

test('dashboard consultation keeps plan action and removes the duplicate tomography action', function () {
    $this->actingAs(User::factory()->create());
    $component = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $actionIndex = array_key_last($component->get('mountedActions'));
    $dataPath = "mountedActions.{$actionIndex}.data";

    $component
        ->set("{$dataPath}.visit_type", 'consultation')
        ->assertMountedActionModalSee('+ გეგმა')
        ->assertMountedActionModalDontSee('კონსულტაციის ფასი')
        ->assertMountedActionModalSee('მკურნალობის გეგმა ჯერ არ არის.')
        ->assertMountedActionModalDontSee('+ გეგმის შექმნა')
        ->assertMountedActionModalDontSee('+ 3D CT')
        ->assertActionExists(TestAction::make('createEstimate')->schemaComponent())
        ->assertActionDoesNotExist(TestAction::make('dashboardVisitTomography')->schemaComponent());
});

test('dashboard opens shared tomography modal and saves one paid tomography visit', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Modal', 'last_name' => 'Patient']);
    $tomography = TreatmentCase::create([
        'name' => '3D CT',
        'category' => 'tomography',
        'default_price' => 60,
        'is_active' => true,
    ]);

    Livewire::test(Dashboard::class)
        ->assertActionExists('cashboxOverview')
        ->assertActionExists('manageTomography')
        ->mountAction('manageTomography')
        ->assertMountedActionModalSee([
            'პაციენტი',
            'ექიმი',
            'სერვისი',
            'გადახდა',
            'შენახვა და გადახდა',
        ]);

    Livewire::test(Dashboard::class)
        ->callAction('manageTomography', [
            'patient_id' => $patient->getKey(),
            'consultation_source' => 'our_patient',
            'currency' => 'GEL',
            'tomographyItems' => [[
                'treatment_case_id' => $tomography->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
            'amount' => 120,
            'paymentSplits' => [[
                'payment_method' => 'cash',
                'amount' => 120,
                'currency' => 'GEL',
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('ტომოგრაფია და გადახდა შენახულია.');

    $visit = Visit::query()->with('treatmentCaseItems', 'payments.splits')->sole();
    expect($visit->patient_id)->toBe($patient->getKey())
        ->and($visit->treatmentCaseItems)->toHaveCount(1)
        ->and((int) $visit->treatmentCaseItems->sole()->quantity)->toBe(2)
        ->and((float) $visit->total_price)->toBe(120.0)
        ->and($visit->payments)->toHaveCount(1)
        ->and(CashboxTransaction::query()->where('visit_id', $visit->getKey())->count())->toBe(1);
});

test('dashboard tomography keeps split payment rows and totals intact', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Split', 'last_name' => 'Tomography']);
    $tomography = TreatmentCase::create([
        'name' => 'Split 3D CT',
        'category' => 'tomography',
        'default_price' => 100,
        'is_active' => true,
    ]);

    Livewire::test(Dashboard::class)
        ->callAction('manageTomography', [
            'patient_id' => $patient->getKey(),
            'consultation_source' => 'our_patient',
            'currency' => 'GEL',
            'tomographyItems' => [[
                'treatment_case_id' => $tomography->getKey(),
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            'amount' => 100,
            'paymentSplits' => [
                ['payment_method' => 'cash', 'amount' => 40, 'currency' => 'GEL'],
                ['payment_method' => 'card', 'amount' => 60, 'currency' => 'GEL'],
            ],
        ])
        ->assertHasNoActionErrors();

    $visit = Visit::query()->with('payments.splits')->sole();

    expect((float) $visit->total_price)->toBe(100.0)
        ->and($visit->payments)->toHaveCount(1)
        ->and($visit->payments->sole()->splits)->toHaveCount(2)
        ->and((float) $visit->payments->sole()->splits->sum('amount'))->toBe(100.0);
});

test('dashboard tomography service selection initializes quantity price and payment state', function () {
    $this->actingAs(User::factory()->create());
    $services = collect([
        TreatmentCase::create([
            'name' => '3D CT',
            'category' => 'tomography',
            'default_price' => 60,
            'is_active' => true,
        ]),
        TreatmentCase::create([
            'name' => 'Panorama',
            'category' => 'tomography',
            'default_price' => 40,
            'is_active' => true,
        ]),
    ]);

    foreach ($services as $service) {
        $component = Livewire::test(Dashboard::class)->mountAction('manageTomography');
        $items = $component->get('mountedActions.0.data.tomographyItems');
        $itemKey = array_key_first($items);

        $component->set(
            "mountedActions.0.data.tomographyItems.{$itemKey}.treatment_case_id",
            $service->getKey(),
        );

        expect((int) $component->get("mountedActions.0.data.tomographyItems.{$itemKey}.quantity"))->toBe(1)
            ->and((float) $component->get("mountedActions.0.data.tomographyItems.{$itemKey}.unit_price"))
            ->toBe((float) $service->default_price)
            ->and((float) $component->get('mountedActions.0.data.amount'))->toBe((float) $service->default_price)
            ->and($component->get('mountedActions.0.data.paymentSplits'))->toHaveCount(1)
            ->and((float) $component->get('mountedActions.0.data.paymentSplits.0.amount'))
            ->toBe((float) $service->default_price);

        $component->set("mountedActions.0.data.tomographyItems.{$itemKey}.quantity", 2);

        expect((float) $component->get('mountedActions.0.data.amount'))->toBe((float) $service->default_price * 2)
            ->and((float) $component->get('mountedActions.0.data.paymentSplits.0.amount'))
            ->toBe((float) $service->default_price * 2);
    }
});

test('tomography inline patient creation supports optional external patient details', function () {
    $patientId = VisitForm::createInlinePatient([
        'first_name' => 'External',
        'last_name' => 'Patient',
        'phone' => null,
        'birth_date' => '1992-04-15',
        'personal_id' => null,
    ]);

    $patient = Patient::query()->findOrFail($patientId);
    expect($patient->full_name)->toBe('External Patient')
        ->and($patient->phone)->toBeNull()
        ->and($patient->birth_date?->toDateString())->toBe('1992-04-15');
});

test('dashboard tomography patient search uses the shared patient search scope', function () {
    $this->actingAs(User::factory()->create());
    $patients = collect([
        Patient::create(['first_name' => 'Searchable', 'last_name' => 'First']),
        Patient::create(['first_name' => 'Second', 'last_name' => 'Searchable']),
        Patient::create(['first_name' => 'Phone', 'last_name' => 'Match', 'phone' => '555123456']),
        Patient::create(['first_name' => 'Personal', 'last_name' => 'Match', 'personal_id' => '01001012345']),
    ]);

    foreach (['Searchable', '555123456', '01001012345'] as $search) {
        $component = Livewire::test(Dashboard::class)->mountAction('manageTomography');
        $component->call(
            'callSchemaComponentMethod',
            'mountedActionSchema0.dashboard-tomography-patient',
            'getSearchResultsForJs',
            ['search' => $search],
        );

        $results = collect(data_get($component->effects, 'returns.0'))->pluck('value')->map(fn ($id): int => (int) $id);
        expect($results->intersect($patients->pluck('id')->all()))->not->toBeEmpty();
    }
});

test('dashboard product sale converts the gel total to usd with the nbg currency action', function () {
    $this->actingAs(User::factory()->create());
    Http::fake([config('services.nbg.rates_url') => Http::response(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>
<GetCurrentRatesResponse xmlns="http://www.nbg.ge/"><GetCurrentRatesResult>
<CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.7000</Rate></CurrencyRate>
</GetCurrentRatesResult></GetCurrentRatesResponse></soap:Body></soap:Envelope>
XML, 200)]);
    $product = Product::create(['name' => 'Dashboard USD product', 'selling_price' => 40, 'is_active' => true]);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->mountAction('dashboardProductSale');
    $actionIndex = array_key_last($component->get('mountedActions'));
    $itemsPath = "mountedActions.{$actionIndex}.data.items";
    $itemKey = array_key_first($component->get($itemsPath));

    $component
        ->set("{$itemsPath}.{$itemKey}.product_id", $product->getKey())
        ->set("{$itemsPath}.{$itemKey}.quantity", 2)
        ->callAction(TestAction::make('toggleProductSaleCurrency')->schemaComponent('payment_amount'));

    expect($component->get("mountedActions.{$actionIndex}.data.currency"))->toBe('USD')
        ->and((float) $component->get("mountedActions.{$actionIndex}.data.exchange_rate"))->toBe(2.7)
        ->and((float) $component->get("mountedActions.{$actionIndex}.data.payment_amount"))->toBe(29.63);
});

test('dashboard product sale modal is compact and records its timestamp automatically', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo(now()->setTime(14, 32));
    $product = Product::create(['name' => 'Compact product', 'selling_price' => 25, 'is_active' => true]);

    $component = Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->mountAction('dashboardProductSale')
        ->assertMountedActionModalSee('პროდუქტი')
        ->assertMountedActionModalSee('გადახდის მეთოდი')
        ->assertMountedActionModalSee('გადასახდელი თანხა')
        ->assertMountedActionModalSee('სულ')
        ->assertMountedActionModalDontSee('თარიღი / დრო');

    app(ProductSaleService::class)->create([
        'items' => [[
            'product_id' => $product->getKey(),
            'quantity' => 2,
            'unit_price' => 25,
        ]],
        'payment_method' => 'cash',
        'currency' => 'GEL',
    ]);

    $sale = ProductSale::query()->sole();
    expect($sale->sold_at->format('H:i'))->toBe('14:32')
        ->and((float) $sale->total)->toBe(50.0);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('14:32');
});

test('dashboard cashbox quick actions open while an older day remains unresolved', function () {
    $this->actingAs(User::factory()->create());
    CashboxDay::create([
        'date' => today()->subDay(),
        'status' => 'open',
        'opened_at' => now()->subDay(),
    ]);

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->mountAction('dashboardOpeningBalance')
        ->assertSet('mountedActions.1.name', 'dashboardOpeningBalance');

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->mountAction('dashboardExpense')
        ->assertSet('mountedActions.1.name', 'dashboardExpense');

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->mountAction('dashboardProductSale')
        ->assertSet('mountedActions.1.name', 'dashboardProductSale');
});

test('dashboard cashier payment list shows patient services doctor amount and payment details', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Cashier', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Detail', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'visit_type' => 'treatment', 'total_price' => 120, 'currency' => 'GEL',
    ]);
    foreach (['Implantation', 'Crown', 'Consultation service'] as $name) {
        $service = TreatmentCase::create(['name' => $name, 'category' => 'therapy', 'is_active' => true]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 40, 'currency' => 'GEL',
        ]);
    }
    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 120, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 120, 'currency' => 'GEL']]);
    $transaction = CashboxTransaction::query()->where('payment_id', $payment->getKey())->sole();

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->assertMountedActionModalSee(['პაციენტი', 'სერვისი', 'ექიმი', 'დეტალების ნახვა'])
        ->assertMountedActionModalDontSee('წყარო')
        ->assertMountedActionModalSee([$patient->full_name, $doctor->full_name, '+120.00 ₾']);

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxPaymentDetails', ['transaction' => $transaction->getKey()])
        ->assertMountedActionModalSee([
            $patient->full_name, $doctor->full_name, 'Visit', '#'.$visit->getKey(),
            'Implantation', 'Crown', 'Consultation service', '×1', '40.00 ₾', '120.00 ₾', 'ნაღდი',
        ]);
});

test('dashboard cashbox shows today while closing still targets the oldest unclosed day', function () {
    $this->actingAs(User::factory()->create());
    Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'Asia/Tbilisi'));
    CashboxDay::create([
        'date' => '2026-09-04', 'status' => 'closed', 'opened_at' => now()->subDays(2),
        'closed_at' => now()->subDays(2), 'actual_closing_balance' => 0,
    ]);

    Livewire::test(Dashboard::class)
        ->mountAction('cashboxOverview')
        ->assertMountedActionModalSee(['სალარო 06.09.2026', 'დღის დახურვა 05.09.2026'])
        ->mountAction('dashboardCloseCashboxDay')
        ->assertMountedActionModalSee(['დღის დახურვა 05.09.2026', '05.09.2026']);

    Carbon::setTestNow();
});
