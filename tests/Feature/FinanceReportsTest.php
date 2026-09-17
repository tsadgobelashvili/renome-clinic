<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Widgets\DoctorDynamicsChart;
use App\Filament\Widgets\FinanceDynamicsChart;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('finance reports show existing totals and category breakdowns', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

    FinanceTransaction::create([
        'type' => 'income',
        'transaction_date' => now(),
        'category' => 'other_income',
        'amount' => 120,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);

    FinanceTransaction::create([
        'type' => 'expense',
        'transaction_date' => now(),
        'category' => 'materials',
        'description' => 'Test materials expense',
        'amount' => 30,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);

    Livewire::test(FinanceReports::class)
        ->assertOk()
        ->assertSeeHtml('wire:key="finance-donut-income-')
        ->assertSeeHtml('class="renome-donut__plot"')
        ->assertViewHas('reportTotal', 120.0)
        ->assertViewHas('reportRows', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['key'] === 'other_income' && $row['amount'] === 120.0,
        ))
        ->call('selectReportTab', 'expense')
        ->assertSeeHtml('wire:key="finance-donut-expense-')
        ->assertViewHas('reportTotal', 30.0)
        ->assertViewHas('reportRows', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['key'] === 'dimension_review' && $row['amount'] === 30.0,
        ))
        ->assertViewHas('breakdownDetails', fn (array $details): bool => $details['dimension_review'] === [['name' => 'მასალები', 'amount' => 30.0]])
        ->call('selectReportTab', 'cash_out')
        ->assertViewHas('reportTotal', 30.0)
        ->call('selectSectionTab', 'dynamics')
        ->assertSet('sectionTab', 'dynamics')
        ->assertSee('შემოსავალი')
        ->assertSeeHtml('fi-wi-chart-canvas-ctn')
        ->call('selectSectionTab', 'doctors')
        ->assertSeeHtml('renome-visits-toolbar__period-dropdown')
        ->assertSet('sectionTab', 'doctors')
        ->assertSee('ექიმების რეიტინგი')
        ->call('selectSectionTab', 'finance')
        ->assertSet('sectionTab', 'finance')
        ->assertSeeHtml('wire:key="finance-donut-cash_out-')
        ->set('dateFrom', today()->subYear()->toDateString())
        ->set('dateUntil', today()->subYear()->toDateString())
        ->assertDontSeeHtml('wire:key="finance-donut-')
        ->assertSee('არჩეულ პერიოდში მონაცემები არ არის.');
});

test('dynamics chart renders the supplied daily or monthly series', function () {
    Livewire::test(FinanceDynamicsChart::class, [
        'labels' => ['01.09', '02.09'],
        'income' => [100, 150],
        'expense' => [40, 50],
        'currency' => 'GEL',
    ])
        ->assertSeeHtml('fi-wi-chart-canvas-ctn')
        ->assertSeeHtml('[100,150]')
        ->assertSeeHtml('[40,50]');
});

test('doctor statistics aggregate unique patients categories and expandable details', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient']);
    $treatment = TreatmentCase::create(['name' => 'Implant', 'category' => 'surgery', 'default_price' => 200, 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 200,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => 2,
        'unit_price' => 100,
        'currency' => 'GEL',
    ]);

    $secondDoctor = Doctor::create(['first_name' => 'Second', 'last_name' => 'Doctor', 'is_active' => true]);
    $secondPatient = Patient::create(['first_name' => 'Second', 'last_name' => 'Patient']);
    $secondTreatment = TreatmentCase::create(['name' => 'Filling', 'category' => 'therapy', 'default_price' => 150, 'is_active' => true]);
    $secondVisit = Visit::create([
        'patient_id' => $secondPatient->id,
        'doctor_id' => $secondDoctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 150,
        'currency' => 'GEL',
    ]);
    $secondVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $secondTreatment->id,
        'quantity' => 1,
        'unit_price' => 150,
        'currency' => 'GEL',
    ]);
    Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 75,
        'currency' => 'USD',
    ]);

    Livewire::test(FinanceReports::class)
        ->call('selectSectionTab', 'doctors')
        ->assertSeeHtml('wire:key="reports-doctors-section"')
        ->assertSeeHtml('data-statistics-donut="category-share"')
        ->assertSeeHtml('data-statistics-donut="doctor-share"')
        ->assertViewHas('doctorStatistics', fn (array $stats): bool => $stats['totalPatients'] === 2
            && $stats['totalRevenue'] === 350.0
            && collect($stats['categories'])->sum('procedures') === 3
            && $stats['doctors'][0]['id'] === $doctor->id)
        ->call('toggleDoctor', $doctor->id)
        ->assertSee('Test Doctor')
        ->assertSee('Implant')
        ->set('doctorDynamicsCurrency', 'both')
        ->assertViewHas('doctorStatistics', function (array $stats) use ($doctor): bool {
            $series = collect($stats['details'][$doctor->id]['dynamics']['series'])->keyBy('label');

            return collect($series['შემოსავალი (GEL)']['data'])->sum() === 200.0
                && collect($series['შემოსავალი (USD)']['data'])->sum() === 75.0;
        })
        ->call('toggleDoctor', $secondDoctor->id)
        ->assertSet('selectedDoctorId', $secondDoctor->id)
        ->assertSee('Second Doctor')
        ->call('selectSectionTab', 'dynamics')
        ->assertSet('sectionTab', 'dynamics')
        ->assertSet('selectedDoctorId', null)
        ->call('selectSectionTab', 'finance')
        ->assertSet('sectionTab', 'finance')
        ->assertSee('მიმდინარე ქეში')
        ->set('dateFrom', today()->subDay()->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->call('toggleDoctor', $doctor->id)
        ->assertSet('selectedDoctorId', $doctor->id)
        ->set('currency', 'USD')
        ->assertSet('selectedDoctorId', null)
        ->assertSee('Test Doctor')
        ->set('currency', 'GEL')
        ->assertSee('Test Doctor')
        ->call('toggleDoctor', $secondDoctor->id)
        ->assertSet('selectedDoctorId', $secondDoctor->id)
        ->call('selectSectionTab', 'dynamics')
        ->call('selectSectionTab', 'finance')
        ->call('selectSectionTab', 'doctors')
        ->assertSet('sectionTab', 'doctors')
        ->assertSet('selectedDoctorId', null)
        ->assertSee('Test Doctor')
        ->assertSee('Second Doctor');
});

test('doctors date presets update the existing report range in one component action', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    app()->setLocale('ka');

    Livewire::test(FinanceReports::class)
        ->call('selectSectionTab', 'doctors')
        ->assertSee('14 დღე')
        ->assertSee('1 თვე')
        ->assertSee('6 თვე')
        ->assertSee('1 წელი')
        ->assertSee('სულ')
        ->call('applyDoctorsDatePreset', '14_days')
        ->assertSet('period', '14_days')
        ->assertSet('dateFrom', today()->subDays(13)->toDateString())
        ->assertSet('dateUntil', today()->toDateString())
        ->call('applyDoctorsDatePreset', '6_months')
        ->assertSet('dateFrom', today()->subMonths(6)->addDay()->toDateString())
        ->call('applyDoctorsDatePreset', '1_year')
        ->assertSet('dateFrom', today()->subYear()->addDay()->toDateString())
        ->call('applyDoctorsDatePreset', 'all')
        ->assertSet('period', 'all')
        ->assertSet('dateFrom', '2000-01-01')
        ->assertDontSee('01.01.2000')
        ->set('dateFrom', today()->subDays(3)->toDateString())
        ->assertSet('period', 'custom');

    app()->setLocale('en');
    Livewire::test(FinanceReports::class)
        ->call('selectSectionTab', 'doctors')
        ->assertSee('14 days')
        ->assertSee('1 month')
        ->assertSee('6 months')
        ->assertSee('1 year')
        ->assertSee('All');
});

test('individual doctor dynamics chart keeps currency series separate', function () {
    Livewire::test(DoctorDynamicsChart::class, [
        'labels' => ['01.09', '02.09'],
        'series' => [
            [
                'label' => 'შემოსავალი (GEL)',
                'data' => [100, 150],
                'color' => '#34d399',
                'backgroundColor' => 'rgba(52, 211, 153, 0.14)',
            ],
            [
                'label' => 'შემოსავალი (USD)',
                'data' => [40, 50],
                'color' => '#60a5fa',
                'backgroundColor' => 'rgba(96, 165, 250, 0.12)',
            ],
        ],
    ])
        ->assertSeeHtml('fi-wi-chart-canvas-ctn')
        ->assertSeeHtml('[100,150]')
        ->assertSeeHtml('[40,50]');
});

test('israeli orthopedics uses distinct lab patients and exact zircon quantity', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Shalva', 'last_name' => 'Test', 'is_active' => true]);
    $groupId = PatientGroup::israelPartnerId();

    foreach ([10, 15, 20, 25, 30] as $index => $quantity) {
        $patient = Patient::create([
            'first_name' => 'Israeli'.$index,
            'last_name' => 'Patient',
            'patient_group_id' => $groupId,
        ]);
        $case = LabCase::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'source' => 'israeli',
            'case_date' => today(),
        ]);
        $case->mainWorks()->create(['material' => 'zircon', 'quantity' => $quantity]);
    }

    Livewire::test(FinanceReports::class)
        ->set('source', 'partner')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats) use ($doctor): bool {
            $orthopedics = collect($stats['categories'])->firstWhere('key', 'orthopedics');
            $doctorRow = collect($stats['doctors'])->firstWhere('id', $doctor->id);

            return $stats['totalPatients'] === 5
                && $orthopedics['patients'] === 5
                && $orthopedics['procedures'] === 100
                && $orthopedics['work_breakdown'][0]['label'] === 'Zirconia crown'
                && $orthopedics['work_breakdown'][0]['quantity'] === 100
                && $orthopedics['work_breakdown'][0]['source'] === 'israeli'
                && $doctorRow['patients'] === 5
                && $doctorRow['procedures'] === 100;
        })
        ->assertSee('Zirconia crown ×100')
        ->assertSee('ისრაელი')
        ->assertSeeHtml('data-stat-source="israeli"');
});

test('israeli laboratory work is counted without a visit price or payment', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Lab', 'last_name' => 'Only', 'is_active' => true]);
    $patient = Patient::create([
        'first_name' => 'No',
        'last_name' => 'Visit',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $case = LabCase::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'source' => 'israeli',
        'case_date' => today(),
    ]);
    $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 12]);

    expect(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);

    Livewire::test(FinanceReports::class)
        ->set('source', 'partner')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $orthopedics = collect($stats['categories'])->firstWhere('key', 'orthopedics');

            return $orthopedics['procedures'] === 12
                && $orthopedics['revenue'] === 0.0
                && $orthopedics['work_breakdown'][0]['label'] === 'PMMA';
        });
});

test('clinic laboratory work does not duplicate clinic visit statistics', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Clinic', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $treatment = TreatmentCase::create(['name' => 'Clinic crown', 'category' => 'orthopedics', 'default_price' => 300, 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 300,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => 3,
        'unit_price' => 100,
        'currency' => 'GEL',
    ]);
    $case = LabCase::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'source' => 'clinic',
        'case_date' => today(),
    ]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 50]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $orthopedics = collect($stats['categories'])->firstWhere('key', 'orthopedics');

            return $orthopedics['procedures'] === 3
                && $orthopedics['work_breakdown'] === [];
        })
        ->assertDontSeeHtml('data-stat-source="israeli"');
});

test('israeli orthopedic visit revenue remains while work quantity comes only from laboratory', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Israeli', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create([
        'first_name' => 'Israeli',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $treatment = TreatmentCase::create(['name' => 'Israeli crown', 'category' => 'orthopedics', 'default_price' => 600, 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 600,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => 6,
        'unit_price' => 100,
        'currency' => 'GEL',
    ]);
    $case = LabCase::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'source' => 'israeli',
        'case_date' => today(),
    ]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 100]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'partner')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $orthopedics = collect($stats['categories'])->firstWhere('key', 'orthopedics');
            $orthopedicGroups = collect($stats['treatmentHierarchy'])->firstWhere('key', 'orthopedics')['groups'];
            $zircon = collect($orthopedicGroups)->firstWhere('key', 'zircon');

            return $orthopedics['procedures'] === 100
                && $orthopedics['patients'] === 1
                && $orthopedics['revenue'] === 600.0
                && $stats['totalRevenue'] === 600.0
                && $zircon['quantity'] === 100
                && $zircon['amount'] === 0.0
                && $zircon['israeliQuantity'] === 100;
        });
});

test('israeli laboratory orthopedic statistics respect the selected date range', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Date', 'last_name' => 'Doctor', 'is_active' => true]);
    $groupId = PatientGroup::israelPartnerId();

    foreach ([[today(), 20], [today()->subMonth(), 40]] as $index => [$date, $quantity]) {
        $patient = Patient::create([
            'first_name' => 'Dated'.$index,
            'last_name' => 'Patient',
            'patient_group_id' => $groupId,
        ]);
        $case = LabCase::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'source' => 'israeli',
            'case_date' => $date,
        ]);
        $case->mainWorks()->create(['material' => 'zircon', 'quantity' => $quantity]);
    }

    Livewire::test(FinanceReports::class)
        ->set('source', 'partner')
        ->set('dateFrom', today()->subDays(2)->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $orthopedics = collect($stats['categories'])->firstWhere('key', 'orthopedics');

            return $orthopedics['patients'] === 1
                && $orthopedics['procedures'] === 20
                && $orthopedics['work_breakdown'][0]['quantity'] === 20;
        });
});

test('statistics groups equivalent manipulation names without changing visit history text', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Therapy', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Therapy', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 500,
        'currency' => 'GEL',
    ]);

    foreach ([['დაბჟენა', 2, 100], ['კბილის დაბჟენა', 3, 100]] as [$name, $quantity, $unitPrice]) {
        $treatment = TreatmentCase::create([
            'name' => $name,
            'category' => 'therapy',
            'statistics_group' => 'filling',
            'is_active' => true,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'currency' => 'GEL',
        ]);
    }

    expect(TreatmentCase::query()->whereIn('name', ['დაბჟენა', 'კბილის დაბჟენა'])->orderBy('id')->pluck('name')->all())
        ->toBe(['დაბჟენა', 'კბილის დაბჟენა']);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $filling = collect($stats['treatmentGroups'])->firstWhere('key', 'filling');

            return $filling['label'] === 'დაბჟენა'
                && $filling['patients'] === 1
                && $filling['quantity'] === 5
                && $filling['amount'] === 500.0;
        })
        ->assertSee('მანიპულაციების ჯგუფები')
        ->assertSee('დაბჟენა');
});

test('analytics keeps grouped manipulations in groups and direct manipulations under their category', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Direct', 'last_name' => 'Category', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Analytics', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 250,
        'currency' => 'GEL',
    ]);
    $grouped = TreatmentCase::create([
        'name' => 'Composite filling',
        'category' => 'therapy',
        'statistics_group' => 'filling',
        'is_active' => true,
    ]);
    $direct = TreatmentCase::create([
        'name' => 'Consultation-specific procedure',
        'category' => 'surgery',
        'statistics_group' => null,
        'is_active' => true,
    ]);

    $visit->treatmentCaseItems()->createMany([
        ['treatment_case_id' => $grouped->id, 'quantity' => 2, 'unit_price' => 50, 'currency' => 'GEL'],
        ['treatment_case_id' => $direct->id, 'quantity' => 3, 'unit_price' => 50, 'currency' => 'GEL'],
    ]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $hierarchy = collect($stats['treatmentHierarchy'])->keyBy('key');
            $therapyGroups = collect($hierarchy['therapy']['groups']);
            $surgeryGroups = collect($hierarchy['surgery']['groups']);
            $direct = $surgeryGroups->firstWhere('label', 'Consultation-specific procedure');

            return $therapyGroups->firstWhere('key', 'filling')['quantity'] === 2
                && $direct['direct'] === true
                && $direct['quantity'] === 3
                && $hierarchy['surgery']['quantity'] === 3
                && $hierarchy['surgery']['amount'] === 150.0
                && $surgeryGroups->doesntContain('key', 'other');
        });

    expect($direct->fresh()->name)->toBe('Consultation-specific procedure')
        ->and($direct->statistics_group)->toBeNull();
});

test('manipulation statistics are classified under their clinical category hierarchy', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Hierarchy', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Hierarchy', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 900,
        'currency' => 'GEL',
    ]);
    $items = [
        ['კბილის დაბჟენა', 'therapy', 2],
        ['Root canal treatment', 'therapy', 3],
        ['წმენდა', 'therapy', 1],
        ['გათეთრება', 'therapy', 1],
        ['წამლის მოთავსება', 'therapy', 1],
        ['იმპლანტაცია - Nova', 'surgery', 4],
        ['ექსტრაქცია - რთული', 'surgery', 2],
        ['სინუს ლიფტი', 'surgery', 1],
        ['აუგმენტაცია', 'surgery', 1],
        ['Zirconia crown', 'orthopedics', 5],
        ['PMMA temporary crown', 'orthopedics', 6],
        ['Denture prosthesis', 'orthopedics', 2],
    ];

    foreach ($items as [$name, $category, $quantity]) {
        $treatment = TreatmentCase::create([
            'name' => $name,
            'category' => $category,
            'statistics_group' => TreatmentCase::inferStatisticsGroup($name),
            'is_active' => true,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->id,
            'quantity' => $quantity,
            'unit_price' => 10,
            'currency' => 'GEL',
        ]);
    }
    $visit->treatmentCaseItems()->create([
        'custom_service_name' => 'Unclassified custom item',
        'quantity' => 1,
        'unit_price' => 10,
        'currency' => 'GEL',
    ]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $hierarchy = collect($stats['treatmentHierarchy'])->keyBy('key');
            $therapy = collect($hierarchy['therapy']['groups'])->pluck('quantity', 'key');
            $surgery = collect($hierarchy['surgery']['groups'])->pluck('quantity', 'key');
            $orthopedics = collect($hierarchy['orthopedics']['groups'])->pluck('quantity', 'key');
            $other = collect($hierarchy['other']['groups'])->pluck('quantity', 'key');
            $totalsMatchChildren = $hierarchy->every(fn (array $category): bool => $category['quantity'] === collect($category['groups'])->sum('quantity')
                && $category['amount'] === round((float) collect($category['groups'])->sum('amount'), 2));

            return $totalsMatchChildren
                && $hierarchy['therapy']['patients'] === 1
                && $therapy->only(['filling', 'endodontics', 'cleaning', 'whitening', 'medication'])->all() === [
                    'filling' => 2, 'cleaning' => 1, 'endodontics' => 3, 'whitening' => 1, 'medication' => 1,
                ]
                && $surgery->only(['implantation', 'extraction', 'sinus_lift', 'augmentation'])->all() === [
                    'implantation' => 4, 'extraction' => 2, 'sinus_lift' => 1, 'augmentation' => 1,
                ]
                && $orthopedics->only(['zircon', 'pmma', 'prosthesis'])->all() === [
                    'zircon' => 5, 'pmma' => 6, 'prosthesis' => 2,
                ]
                && $other->all() === ['other' => 1];
        })
        ->assertSee('თერაპია')
        ->assertSee('ქირურგია')
        ->assertSee('ორთოპედია')
        ->assertSeeHtml('data-statistics-category-row="therapy"')
        ->assertSeeHtml('data-statistics-group-row="therapy-filling"')
        ->assertSeeHtml('x-cloak')
        ->assertDontSeeHtml('data-statistics-group-row="other-other"')
        ->assertDontSeeHtml('wire:click="toggleTreatmentCategory');
});

test('implantation statistics aggregate fixture quantity by catalog brand', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Implant', 'last_name' => 'Doctor', 'is_active' => true]);
    $clinicPatient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Implant']);
    $israeliPatient = Patient::create([
        'first_name' => 'Israeli',
        'last_name' => 'Implant',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $treatments = collect([
        'nova' => TreatmentCase::create(['name' => 'იმპლანტაცია - Nova', 'category' => 'surgery', 'statistics_group' => 'implantation', 'is_active' => true]),
        'osstem' => TreatmentCase::create(['name' => 'იმპლანტაცია - Osstem', 'category' => 'surgery', 'statistics_group' => 'implantation', 'is_active' => true]),
        'augmentation' => TreatmentCase::create(['name' => 'აუგმენტაცია - Bone', 'category' => 'surgery', 'statistics_group' => 'augmentation', 'is_active' => true]),
        'sinus' => TreatmentCase::create(['name' => 'სინუს ლიფტი', 'category' => 'surgery', 'statistics_group' => 'sinus_lift', 'is_active' => true]),
    ]);

    $addItem = function (Patient $patient, TreatmentCase $treatment, int $quantity, $date = null) use ($doctor): void {
        $visit = Visit::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'visit_date' => $date ?? today(),
            'visit_type' => 'treatment',
            'total_price' => $quantity * 100,
            'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->id,
            'quantity' => $quantity,
            'unit_price' => 100,
            'currency' => 'GEL',
        ]);
    };

    $addItem($clinicPatient, $treatments['nova'], 10);
    $addItem($clinicPatient, $treatments['nova'], 5);
    $addItem($clinicPatient, $treatments['osstem'], 4);
    $addItem($clinicPatient, $treatments['augmentation'], 8);
    $addItem($clinicPatient, $treatments['sinus'], 6);
    $addItem($clinicPatient, $treatments['nova'], 50, today()->subMonth());
    $addItem($israeliPatient, $treatments['nova'], 70);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->set('dateFrom', today()->subDay()->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $implantation = collect($stats['treatmentGroups'])->firstWhere('key', 'implantation');
            $augmentation = collect($stats['treatmentGroups'])->firstWhere('key', 'augmentation');
            $sinus = collect($stats['treatmentGroups'])->firstWhere('key', 'sinus_lift');

            return $implantation['quantity'] === 19
                && collect($implantation['breakdown'])->pluck('quantity', 'label')->all() === ['Nova' => 15, 'Osstem' => 4]
                && $augmentation['quantity'] === 8
                && $sinus['quantity'] === 6;
        })
        ->call('toggleDoctor', $doctor->id)
        ->assertViewHas('doctorStatistics', function (array $stats) use ($doctor): bool {
            $procedures = collect($stats['details'][$doctor->id]['procedures'])->pluck('count', 'name');

            return $procedures->get('იმპლანტაცია - Nova') === 15
                && $procedures->get('იმპლანტაცია - Osstem') === 4;
        })
        ->assertSee('Nova ×15')
        ->assertSee('Osstem ×4');

    expect($treatments['nova']->fresh()->name)->toBe('იმპლანტაცია - Nova');
});

test('consultation conversion uses a seven day maturity window and lazy details', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Conversion', 'last_name' => 'Doctor', 'is_active' => true]);
    $therapy = TreatmentCase::create(['name' => 'კომპოზიტური დაბჟენა', 'category' => 'therapy', 'is_active' => true]);
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'is_active' => true]);
    $pendingToday = Patient::create(['first_name' => 'Today', 'last_name' => 'Pending']);
    $pendingDaySix = Patient::create(['first_name' => 'Six', 'last_name' => 'Pending']);
    $notStartedDaySeven = Patient::create(['first_name' => 'Seven', 'last_name' => 'Not Started', 'phone' => '555700']);
    $converted = Patient::create(['first_name' => 'Converted', 'last_name' => 'Patient']);
    $ctOnly = Patient::create(['first_name' => 'CT', 'last_name' => 'Only']);
    $planOnly = Patient::create(['first_name' => 'Plan', 'last_name' => 'Only']);
    $multiple = Patient::create(['first_name' => 'Multiple', 'last_name' => 'Consultations']);

    foreach ([[$pendingToday, today()], [$pendingDaySix, today()->subDays(6)], [$notStartedDaySeven, today()->subDays(7)]] as [$patient, $date]) {
        Visit::create([
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => $date,
            'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
        ]);
    }

    Visit::create([
        'patient_id' => $converted->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDays(3),
        'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
    ]);
    $treatmentVisit = Visit::create([
        'patient_id' => $converted->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDay(),
        'visit_type' => 'treatment', 'total_price' => 100, 'currency' => 'GEL',
    ]);
    $treatmentVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $therapy->id, 'quantity' => 1, 'unit_price' => 100, 'currency' => 'GEL',
    ]);

    Visit::create([
        'patient_id' => $ctOnly->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDays(8),
        'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
    ]);
    $ctVisit = Visit::create([
        'patient_id' => $ctOnly->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDay(),
        'visit_type' => 'treatment', 'total_price' => 60, 'currency' => 'GEL',
    ]);
    $ctVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $ct->id, 'quantity' => 1, 'unit_price' => 60, 'currency' => 'GEL',
    ]);

    $planConsultation = Visit::create([
        'patient_id' => $planOnly->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDays(9),
        'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
    ]);
    $estimate = TreatmentEstimate::create([
        'patient_id' => $planOnly->id,
        'doctor_id' => $doctor->id,
        'visit_id' => $planConsultation->id,
        'estimate_date' => today()->subDays(8),
    ]);
    Visit::create([
        'patient_id' => $planOnly->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subDays(2),
        'visit_type' => 'treatment', 'treatment_estimate_id' => $estimate->id, 'total_price' => 0, 'currency' => 'GEL',
    ]);

    foreach ([today()->subDays(10), today()->subDays(2)] as $date) {
        Visit::create([
            'patient_id' => $multiple->id, 'doctor_id' => $doctor->id, 'visit_date' => $date,
            'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
        ]);
    }

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->set('dateFrom', today()->subDays(12)->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn (array $stats): bool => $stats['consultations'] === [
            'total' => 7,
            'started' => 1,
            'pending' => 2,
            'notStarted' => 4,
            'conversion' => 20.0,
            'notStartedPatients' => [],
        ])
        ->assertSet('showNotStartedPatients', false)
        ->call('toggleNotStartedPatients')
        ->assertSet('showNotStartedPatients', true)
        ->assertViewHas('doctorStatistics', function (array $stats) use ($converted, $notStartedDaySeven): bool {
            $patients = collect($stats['consultations']['notStartedPatients']);
            $daySeven = $patients->firstWhere('id', $notStartedDaySeven->id);

            return $patients->count() === 4
                && $daySeven['phone'] === '555700'
                && $daySeven['days'] === 7
                && $daySeven['doctor'] === 'Conversion Doctor'
                && ! $patients->contains('id', $converted->id);
        })
        ->assertSee('Seven Not Started')
        ->assertSee('Multiple Consultations')
        ->assertDontSee('Today Pending');
});

test('tomography has separate patient and quantity totals and is excluded from doctor work', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Imaging', 'last_name' => 'Doctor', 'is_active' => true]);
    $patients = collect(range(1, 3))->map(fn (int $number): Patient => Patient::create([
        'first_name' => 'Image'.$number,
        'last_name' => 'Patient',
    ]));
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'is_active' => true]);
    $panorama = TreatmentCase::create(['name' => 'პანორამა', 'category' => 'tomography', 'is_active' => true]);
    $therapy = TreatmentCase::create(['name' => 'არხის მკურნალობა', 'category' => 'therapy', 'is_active' => true]);

    foreach ([[$patients[0], $ct, 2], [$patients[0], $ct, 1], [$patients[1], $ct, 3], [$patients[1], $panorama, 1], [$patients[2], $panorama, 2]] as [$patient, $service, $quantity]) {
        $visit = Visit::create([
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(),
            'visit_type' => 'treatment', 'total_price' => $quantity * 50, 'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->id, 'quantity' => $quantity, 'unit_price' => 50, 'currency' => 'GEL',
        ]);
    }

    $therapyVisit = Visit::create([
        'patient_id' => $patients[0]->id, 'doctor_id' => $doctor->id, 'visit_date' => today(),
        'visit_type' => 'treatment', 'total_price' => 400, 'currency' => 'GEL',
    ]);
    $therapyVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $therapy->id, 'quantity' => 4, 'unit_price' => 100, 'currency' => 'GEL',
    ]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats) use ($doctor): bool {
            $doctorRow = collect($stats['doctors'])->firstWhere('id', $doctor->id);

            return $stats['tomography']['ct'] === ['patients' => 2, 'quantity' => 6]
                && $stats['tomography']['panorama'] === ['patients' => 2, 'quantity' => 3]
                && collect($stats['categories'])->doesntContain('key', 'tomography')
                && collect($stats['categories'])->doesntContain('key', 'consultation')
                && $doctorRow['procedures'] === 4
                && $doctorRow['revenue'] === 400.0;
        });
});

test('new analytics aggregates respect the selected consultation and service date period', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Period', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Period', 'last_name' => 'Patient']);
    $filling = TreatmentCase::create(['name' => 'დაბჟენა', 'category' => 'therapy', 'statistics_group' => 'filling', 'is_active' => true]);
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'is_active' => true]);

    foreach ([[today(), $filling, 2], [today()->subMonth(), $filling, 9], [today()->subMonth(), $ct, 7]] as [$date, $service, $quantity]) {
        $visit = Visit::create([
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => $date,
            'visit_type' => 'treatment', 'total_price' => $quantity * 10, 'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->id, 'quantity' => $quantity, 'unit_price' => 10, 'currency' => 'GEL',
        ]);
    }
    Visit::create([
        'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->subMonth(),
        'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
    ]);

    Livewire::test(FinanceReports::class)
        ->set('source', 'clinic')
        ->set('dateFrom', today()->subDays(2)->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats): bool {
            $filling = collect($stats['treatmentGroups'])->firstWhere('key', 'filling');

            return $filling['quantity'] === 2
                && $stats['tomography']['ct']['quantity'] === 0
                && $stats['consultations']['total'] === 0;
        });
});
