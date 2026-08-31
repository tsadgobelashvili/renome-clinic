<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
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
        ->assertActionExists('cashboxOverview')
        ->assertActionExists('manageTomography');
});

test('operational dashboard shows centralized cashbox and todays tomography summary', function () {
    $this->actingAs(User::factory()->create());
    $cashbox = app(CashboxManager::class);
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
        ->assertSee('დღეს: 2')
        ->assertSee('120.00 ₾')
        ->assertSee('Dashboard 3D CT x2')
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
