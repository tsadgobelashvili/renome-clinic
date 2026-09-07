<?php

use App\Filament\Resources\PartnerPatients\Pages\ViewPartnerPatient;
use App\Filament\Resources\PartnerPatients\RelationManagers\PartnerPaymentsRelationManager;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\Visit;
use App\Support\PartnerPatientHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->doctor = Doctor::create(['first_name' => 'David', 'last_name' => 'Chumburidze', 'is_active' => true]);
});

test('a newly created standalone lab case appears on the already loaded patient profile once with all work details', function () {
    $page = Livewire::test(ViewPartnerPatient::class, ['record' => $this->patient->id])->assertOk();
    $modeler = User::factory()->create(['name' => 'Selected Modeler']);
    $technician = Employee::create(['first_name' => 'Main', 'last_name' => 'Technician', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id]);
    $case = LabCase::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'case_date' => '2026-09-07', 'source' => 'israeli', 'modeled_by' => $modeler->id, 'notes' => 'Unique lab note <script>alert(1)</script>']);
    foreach (['zircon', 'pmma'] as $material) {
        $case->mainWorks()->create(['material' => $material, 'quantity' => 6, 'shade' => 'A2', 'technician_id' => $technician->id]);
    }
    foreach (['milling', 'individual_abutment', 'titanium_bar_modeling'] as $type) {
        $case->additionalWorks()->create(['work_type' => $type, 'quantity' => 2, 'technician_id' => $technician->id]);
    }
    $page->call('$refresh')->assertSee('LAB')->assertSee('Zircon')->assertSee('PMMA')
        ->assertSee('Selected Modeler')->assertSee('Main Technician')->assertSee('A2')
        ->assertSee($this->doctor->full_name)->assertSee('07.09.2026')->assertSee('Unique lab note')
        ->assertSee(__('lab.additional_types.milling'))->assertSee(__('lab.additional_types.individual_abutment'))
        ->assertSee(__('lab.additional_types.titanium_bar_modeling'))
        ->assertDontSeeHtml('<script>alert(1)</script>');
    expect($this->patient->visits()->count())->toBe(0)
        ->and(PartnerPatientHistory::rows($this->patient))->toHaveCount(1);
});

test('lab and visit history sort together and exclude other patients without depending on lab source', function () {
    $older = LabCase::create(['patient_id' => $this->patient->id, 'case_date' => '2026-09-01', 'source' => 'clinic', 'material' => 'pmma', 'quantity' => 3, 'shade' => 'B1']);
    $visit = Visit::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => '2026-09-04', 'total_price' => 100, 'currency' => 'GEL']);
    $latest = LabCase::create(['patient_id' => $this->patient->id, 'case_date' => '2026-09-07', 'source' => 'external', 'material' => 'pmma', 'quantity' => 4]);
    $other = Patient::create(['first_name' => 'Other', 'last_name' => 'Patient']);
    LabCase::create(['patient_id' => $other->id, 'case_date' => '2026-09-09', 'source' => 'clinic', 'notes' => 'Other patient private note']);
    $rows = PartnerPatientHistory::rows($this->patient);
    expect(array_column($rows, 'key'))->toBe(['lab-'.$latest->id, 'visit-'.$visit->id, 'lab-'.$older->id])
        ->and($rows[2]['work'])->toBe('PMMA × 3')->and($rows[2]['shade'])->toBe('B1');
    Livewire::test(ViewPartnerPatient::class, ['record' => $this->patient->id])->assertSee('PMMA')->assertDontSee('Other patient private note');
});

test('legacy milling details are shown but not repeated when an additional milling row exists', function () {
    $case = LabCase::create(['patient_id' => $this->patient->id, 'source' => 'clinic', 'case_date' => today(),
        'milling_quantity' => 6, 'milling_technician' => 'Legacy Miller']);
    expect(PartnerPatientHistory::rows($this->patient)[0]['additional'])->toContain('Legacy Miller');
    $case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 6, 'technician' => 'Assigned Miller']);
    $additional = PartnerPatientHistory::rows($this->patient)[0]['additional'];
    expect($additional)->toContain('Assigned Miller')->not->toContain('Legacy Miller')
        ->and(substr_count($additional, '× 6'))->toBe(1);
});

test('treatment rows retain each material quantity shade and assigned technician', function () {
    $case = LabCase::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    foreach ([['zircon', 6, 'A1', 'Zircon'], ['pmma', 8, 'B2', 'Temporary']] as [$material, $quantity, $shade, $name]) {
        $employee = Employee::create(['first_name' => $name, 'last_name' => 'Technician', 'is_active' => true,
            'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id]);
        $case->mainWorks()->create(['material' => $material, 'quantity' => $quantity, 'shade' => $shade, 'technician_id' => $employee->id]);
    }
    expect(PartnerPatientHistory::rows($this->patient)[0]['details'])->toBe([
        ['material' => 'Zircon', 'quantity' => 6, 'shade' => 'A1', 'technician' => 'Zircon Technician'],
        ['material' => 'PMMA', 'quantity' => 8, 'shade' => 'B2', 'technician' => 'Temporary Technician'],
    ]);
});

test('profile always renders payment history with original currencies and separate totals', function () {
    $gel = $this->patient->partnerPayments()->create(['amount' => 320, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => '2026-09-01', 'notes' => 'Older GEL payment']);
    $usd = $this->patient->partnerPayments()->create(['amount' => 125.50, 'currency' => 'USD', 'payment_method' => 'card', 'paid_at' => '2026-09-07', 'notes' => 'Recent USD payment']);
    Livewire::test(ViewPartnerPatient::class, ['record' => $this->patient->id])->assertSee('320.00')
        ->assertSee('$125.50')->assertSee('Older GEL payment')->assertSee('Recent USD payment');
    Livewire::test(PartnerPaymentsRelationManager::class, [
        'ownerRecord' => $this->patient, 'pageClass' => ViewPartnerPatient::class,
    ])->assertCanSeeTableRecords([$usd, $gel], inOrder: true)->assertSee('Total GEL')->assertSee('Total USD');
    expect($this->patient->getPartnerPaymentTotals())->toMatchArray(['GEL' => 320.0, 'USD' => 125.5]);
});
