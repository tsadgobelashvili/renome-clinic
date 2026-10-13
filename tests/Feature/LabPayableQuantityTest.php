<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\LabCases\Schemas\LabCaseForm;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\LabTechnicianRate;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeeSalaryService;
use App\Services\IsraeliLabSalaryItems;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\LabPartyAutocomplete;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-12 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Payable', 'last_name' => 'Doctor', 'is_active' => true, 'specialties' => ['orthopedics'],
        'israeli_lab_zircon_rate' => 70, 'israeli_lab_pmma_rate' => 25, 'israeli_salary_payment_method' => 'bank_transfer']);
    $patient = Patient::create(['first_name' => 'Payable', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    $this->work = $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 28]);
    $this->salary = app(EmployeeSalaryService::class);
});

function payableTechnician(array $roles, array $rates): Employee
{
    $employee = Employee::create(['first_name' => 'Payable', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true,
        'salary_active' => true, 'salary_type' => 'performance', ...$roles]);
    foreach ($rates as $type => $rate) {
        $employee->salaryRates()->create(['work_type' => $type, 'amount' => $rate, 'basis' => 'per_unit', 'is_active' => true]);
    }

    return $employee;
}

test('payable quantity uses a direct override without changing actual lab quantities', function () {
    expect($this->work->fresh()->payable_quantity)->toBeNull()->and($this->work->payableQuantity())->toBe(28);
    $this->work->update(['quantity' => 25, 'payable_quantity' => 5]);
    expect($this->work->fresh()->payableQuantity())->toBe(5)->and($this->work->fresh()->quantity)->toBe(25)
        ->and($this->case->fresh()->quantity)->toBe(25)->and($this->case->mainWorks()->sum('quantity'))->toBe(25);
    $page = Livewire::test(FinanceReports::class)->set('source', 'partner');
    $statistics = (new ReflectionMethod(FinanceReports::class, 'israeliOrthopedicStatistics'))->invoke($page->instance(), today()->startOfMonth(), today());
    expect($statistics['quantity'])->toBe(25);
});

test('actual 25 uses NULL as full quantity and explicit overrides as exact salary units', function ($override, int $expected) {
    $this->work->update(['quantity' => 25, 'payable_quantity' => $override]);
    $work = $this->work->fresh();
    expect($work->payableQuantity())->toBe($expected)->and($work->quantity)->toBe(25)
        ->and($this->case->fresh()->quantity)->toBe(25)
        ->and(app(IsraeliLabSalaryItems::class)->amount($work, $this->doctor))->toBe((float) ($expected * 70));
    $tech = payableTechnician(['salary_main_technician' => true, 'salary_modeler' => true], ['zircon' => 2, 'zircon_modeling' => 3]);
    $work->update(['technician_id' => $tech->id]);
    $rows = $this->salary->pending($tech);
    if ($expected === 0) {
        expect($rows)->toBeEmpty();
    } else {
        expect($rows->pluck('quantity')->all())->toEqual([$expected, $expected])
            ->and($rows->sum('amount_gel'))->toBe((float) ($expected * 5));
    }
})->with([[null, 25], [5, 5], [0, 0], ['', 25]]);

test('payable quantity cannot exceed actual 25 or be negative or fractional', function ($override) {
    $this->work->update(['quantity' => 25]);
    expect(fn () => $this->work->update(['payable_quantity' => $override]))->toThrow(ValidationException::class);
    expect($this->work->fresh()->payable_quantity)->toBeNull()->and($this->work->fresh()->quantity)->toBe(25);
})->with([26, -1, 1.5]);

test('Owner form persists explicit zero and clearing it restores full actual quantity', function () {
    $this->work->update(['quantity' => 25, 'payable_quantity' => 5]);
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    $page->set('data.mainWorks.'.$key.'.payable_quantity', 0)->call('save')->assertHasNoFormErrors();
    expect($this->work->fresh()->payable_quantity)->toBe(0)->and($this->work->fresh()->payableQuantity())->toBe(0);
    $page->set('data.mainWorks.'.$key.'.payable_quantity', '')->call('save')->assertHasNoFormErrors();
    expect($this->work->fresh()->payable_quantity)->toBeNull()->and($this->work->fresh()->payableQuantity())->toBe(25);
});

test('separate Owner controls update their own work rows without changing produced units or work IDs', function () {
    $this->work->update(['quantity' => 25]);
    $pmma = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 25]);
    $ids = $this->case->mainWorks()->pluck('id')->all();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $page->set('data.mainWorks.record-'.$this->work->id.'.payable_quantity', 5)
        ->set('data.mainWorks.record-'.$pmma->id.'.payable_quantity', 0)
        ->call('save')->assertHasNoFormErrors();
    expect($this->work->fresh()->payableQuantity())->toBe(5)->and($pmma->fresh()->payableQuantity())->toBe(0)
        ->and($this->work->fresh()->quantity)->toBe(25)->and($pmma->fresh()->quantity)->toBe(25)
        ->and($this->case->mainWorks()->pluck('id')->all())->toEqual($ids);
});

test('non Owner cannot clear a saved override or explicitly create zero payable units', function () {
    $this->work->update(['payable_quantity' => 5]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    expect(fn () => $this->work->fresh()->update(['payable_quantity' => null]))->toThrow(AuthorizationException::class);
    expect(fn () => $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 25, 'payable_quantity' => 0]))->toThrow(AuthorizationException::class);
    expect($this->work->fresh()->payable_quantity)->toBe(5);
});

test('follow-up migration preserves old adjustments and full reductions without touching accounting', function () {
    $migration = require database_path('migrations/2026_10_13_120000_replace_lab_redo_with_payable_quantity.php');
    $this->work->update(['quantity' => 25]);
    $second = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 25]);
    $third = $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 25]);
    // RefreshDatabase is restricted to disposable in-memory SQLite by Tests\TestCase.
    $migration->down();
    DB::table('lab_main_works')->where('id', $second->id)->update(['redo_quantity' => 20]);
    DB::table('lab_main_works')->where('id', $third->id)->update(['redo_quantity' => 25]);
    $before = DB::table('lab_main_works')->pluck('quantity', 'id')->all();
    $finance = DB::table('finance_transactions')->get()->toArray();
    $migration->up();
    expect($this->work->fresh()->payable_quantity)->toBeNull()
        ->and($second->fresh()->payable_quantity)->toBe(5)->and($third->fresh()->payable_quantity)->toBe(0)
        ->and(DB::table('lab_main_works')->pluck('quantity', 'id')->all())->toEqual($before)
        ->and(DB::table('finance_transactions')->get()->toArray())->toEqual($finance);
});

test('payable override rejects negative fractional and excess values', function ($override) {
    expect(fn () => $this->work->update(['payable_quantity' => $override]))->toThrow(ValidationException::class);
    expect($this->work->fresh()->payable_quantity)->toBeNull();
})->with([-1, 29, 1.5]);

test('Owner form shows payable override and reacts to quantity changes', function () {
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    $page->assertFormFieldExists('mainWorks.'.$key.'.payable_quantity')
        ->set('data.mainWorks.'.$key.'.payable_quantity', 11)
        ->assertSee(__('lab.payable_quantity'))->set('data.mainWorks.'.$key.'.quantity', 30)
        ->assertSet('data.mainWorks.'.$key.'.payable_quantity', 11)
        ->set('data.mainWorks.'.$key.'.quantity', 28)
        ->call('save')->assertHasNoFormErrors();
    expect($this->work->fresh()->payable_quantity)->toBe(11)->and($this->work->fresh()->quantity)->toBe(28);
});

test('non Owners cannot change payable override at the model boundary and serialization hides it', function (string $role) {
    $this->work->update(['payable_quantity' => 11]);
    $this->actingAs(User::factory()->create(['role' => $role]));
    expect($this->work->fresh()->toArray())->not->toHaveKey('payable_quantity');
    expect(fn () => $this->work->fresh()->update(['payable_quantity' => 0]))->toThrow(AuthorizationException::class);
    expect($this->work->fresh()->payable_quantity)->toBe(11);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('every non Owner role has no payable card in the schema including hidden components', function (string $role) {
    $this->work->update(['payable_quantity' => 5]);
    $this->actingAs(User::factory()->create(['role' => $role]));
    $components = LabCaseForm::configure(Schema::make())->getComponents(withHidden: true);
    expect(collect($components)->filter(fn ($component): bool => $component instanceof Section
        && array_key_exists('data-lab-payable-adjustment', $component->getExtraAttributes())))->toBeEmpty()
        ->and($this->work->fresh()->toArray())->not->toHaveKey('payable_quantity');
})->with(array_values(array_diff(User::ROLES, [User::ROLE_OWNER])));

test('technician edit form omits payable override and rejects a crafted payable override request', function () {
    $this->work->update(['payable_quantity' => 11]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    expect($page->get('data.mainWorks.'.$key))->not->toHaveKey('payable_quantity');
    $page->assertFormFieldDoesNotExist('mainWorks.'.$key.'.payable_quantity')
        ->assertDontSee(__('lab.payable_adjustment'))->assertDontSee(__('lab.payable_quantity'))
        ->assertDontSee(__('lab.payable_default'))->assertDontSee('data-lab-payable-adjustment');
    $page->set('data.mainWorks.'.$key.'.payable_quantity', 0)->call('save');
    expect($this->work->fresh()->payable_quantity)->toBe(11)->and($this->work->fresh()->quantity)->toBe(28);
});

test('doctor compensation uses direct payable units and explicit zero excludes unpaid work', function () {
    expect(app(IsraeliLabSalaryItems::class)->amount($this->work, $this->doctor))->toBe(1960.0);
    $this->work->update(['payable_quantity' => 11]);
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-10-01', '2026-10-12', patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true);
    $item = $report['details'][0]['items'][0];
    expect($item['quantity'])->toBe(11)->and($item['doctor_share'])->toBe(770.0)
        ->and(app(IsraeliLabSalaryItems::class)->eligibleForDoctors(collect([$this->doctor]), compact: true)->sole()->payableQuantity())->toBe(11);
    $this->work->update(['payable_quantity' => 0]);
    expect(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty();
});

test('production and modeling use payable main work units while independent milling stays unchanged', function () {
    $tech = payableTechnician(['salary_main_technician' => true, 'salary_modeler' => true, 'salary_milling_eligible' => true], ['zircon' => 2, 'zircon_modeling' => 3, 'milling' => 4]);
    $this->work->update(['technician_id' => $tech->id, 'payable_quantity' => 11]);
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 28, 'technician_id' => $tech->id]);
    $rows = $this->salary->pending($tech)->keyBy('work_type');
    expect($rows['zircon']['quantity'])->toBe(11)->and($rows['zircon']['amount_gel'])->toBe(22.0)
        ->and($rows['zircon_modeling']['quantity'])->toBe(11)->and($rows['zircon_modeling']['amount_gel'])->toBe(33.0)
        ->and($rows['milling']['quantity'])->toBe(28)->and($rows['milling']['amount_gel'])->toBe(112.0)
        ->and($this->salary->pendingTotals(collect([$tech]))[$tech->id])->toBe(167.0);
});

test('paid production remains frozen while unpaid modeling uses an Owner payable override correction', function () {
    seedTechnicianClinicCash();
    $tech = payableTechnician(['salary_main_technician' => true, 'salary_modeler' => true], ['zircon' => 2, 'zircon_modeling' => 3]);
    $this->work->update(['technician_id' => $tech->id, 'payable_quantity' => 11]);
    $key = 'main-'.$this->work->id.'-employee-'.$tech->id.'-zircon';
    $settlement = settleTechnicianWithClinicCash($tech, [$key]);
    $items = $settlement->items()->get()->toArray();
    $finance = DB::table('finance_transactions')->get()->toArray();
    $this->work->update(['payable_quantity' => 7]);
    $pending = $this->salary->pending($tech)->sole();
    expect($pending['work_type'])->toBe('zircon_modeling')->and($pending['quantity'])->toBe(7)->and($pending['amount_gel'])->toBe(21.0)
        ->and($settlement->items()->get()->toArray())->toEqual($items)->and(DB::table('finance_transactions')->get()->toArray())->toEqual($finance);
    expect(fn () => settleTechnicianWithClinicCash($tech, [$key]))->toThrow(ValidationException::class);
    settleTechnicianWithClinicCash($tech, $this->salary->pending($tech)->keys()->all());
    $financeAfter = DB::table('finance_transactions')->get()->toArray();
    $this->work->update(['payable_quantity' => null]);
    expect($this->salary->pending($tech))->toBeEmpty()->and(DB::table('finance_transactions')->get()->toArray())->toEqual($financeAfter);
});

test('doctor partial payout snapshots and remaining payment stay frozen after payable override correction', function () {
    $this->work->update(['payable_quantity' => 11]);
    $service = app(IsraeliSalaryPayoutService::class);
    $payout = $service->finalizeAndPay($this->doctor->id, '2026-10-01', '2026-10-12', [$this->work->id],
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 200]], (string) Str::uuid(), $this->owner);
    $settlement = $payout->settlement;
    $items = $settlement->items()->get()->toArray();
    $snapshot = $payout->composition_snapshot;
    $finance = DB::table('partner_finance_transactions')->get()->toArray();
    $this->work->update(['payable_quantity' => 8]);
    expect(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty()
        ->and($settlement->items()->get()->toArray())->toEqual($items)->and($payout->fresh()->composition_snapshot)->toEqual($snapshot)
        ->and(DB::table('partner_finance_transactions')->get()->toArray())->toEqual($finance)->and($service->remaining($settlement))->toBe(570.0);
    $next = $service->payRemaining($settlement->id, [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 570]], (string) Str::uuid(), $this->owner);
    expect((float) $next->total_gel)->toBe(570.0)->and($next->composition_snapshot['items'][0]['quantity'])->toBe(11)
        ->and($service->remaining($settlement))->toBe(0.0)->and($this->work->fresh()->quantity)->toBe(28);
});

test('PMMA carry and fully paid credits use new payable Zircon units and immutable quantities', function (float $paid, float $reduction, float $carry) {
    seedTechnicianClinicCash();
    $tech = payableTechnician(['salary_modeler' => true], ['pmma_modeling' => 5, 'zircon_modeling' => 10]);
    $pmmaCase = LabCase::create(['source' => 'clinic', 'patient_id' => Patient::create(['first_name' => 'Credit', 'last_name' => 'Patient'])->id, 'case_date' => today()]);
    $pmma = $pmmaCase->mainWorks()->create(['material' => 'pmma', 'quantity' => 28, 'technician_id' => $tech->id]);
    $old = $this->salary->settle($tech, $this->salary->pending($tech)->keys()->all(), allocation: ['clinic_cash_gel' => $paid, 'israeli_cash_gel' => 0]);
    $items = $old->items()->get()->toArray();
    $pmmaCase->mainWorks()->create(['material' => 'zircon', 'quantity' => 28, 'payable_quantity' => 11, 'technician_id' => $tech->id]);
    $row = $this->salary->pending($tech)->sole();
    expect($row['quantity'])->toBe(11)->and($row['amount_gel'])->toBe(0.0)->and((float) $row['carry_reduction_gel'])->toBe($reduction)
        ->and((float) $row['overpayment_gel'])->toBe(30.0 - $reduction)
        ->and($this->salary->openingCarry($tech))->toBe($carry)->and($old->items()->get()->toArray())->toEqual($items)
        ->and($pmma->quantity)->toBe(28);
})->with([[50.0, 30.0, 60.0], [140.0, 0.0, 0.0]]);

test('non Owners cannot create nonzero payable override and quantity edits must retain a valid payable quantity', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(fn () => $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 28, 'payable_quantity' => 11]))->toThrow(AuthorizationException::class);
    $this->actingAs($this->owner);
    $this->work->update(['payable_quantity' => 11]);
    expect(fn () => $this->work->fresh()->update(['quantity' => 10]))->toThrow(ValidationException::class);
    expect($this->work->fresh()->quantity)->toBe(28);
});

test('legacy case-level lab salary rows block ambiguous payable override changes', function () {
    LabTechnicianRate::create(['technician_id' => $this->owner->id, 'work_type' => 'zirconia', 'component_type' => 'production',
        'rate_per_unit' => 2, 'effective_from' => today(), 'is_active' => true]);
    $legacy = $this->case->workItems()->create(['work_type' => 'zirconia', 'component_type' => 'production', 'quantity' => 28,
        'technician_id' => $this->owner->id, 'work_date' => today(), 'status' => 'completed']);
    $before = $legacy->fresh()->getAttributes();
    expect(fn () => $this->work->update(['payable_quantity' => 11]))->toThrow(ValidationException::class, __('lab.payable_legacy_locked'));
    expect($this->work->fresh()->payable_quantity)->toBeNull()->and($legacy->fresh()->getAttributes())->toEqual($before);
});

test('Owner creates a lab case with persisted payable override and Admin cannot open the control', function () {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'israeli', 'mainWorks' => [['doctor_search' => app(LabPartyAutocomplete::class)->practitionerLabel($this->doctor),
            'patient_search' => $this->case->patient->lab_selection_label, 'material' => 'zircon', 'quantity' => 28, 'payable_quantity' => 11]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $created = LabCase::latest('id')->first()->mainWorks()->sole();
    expect($created->payable_quantity)->toBe(11)->and($created->quantity)->toBe(28)->and($created->payableQuantity())->toBe(11);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->case->update(['created_by' => auth()->id()]);
    Livewire::test(EditLabCase::class, ['record' => $this->case->id])->assertForbidden()
        ->assertDontSee(__('lab.payable_quantity'))->assertDontSee(__('lab.payable_default'));
});

test('PMMA and Zircon keep the original table and Owner controls follow it before Additional Works', function () {
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 28]);
    $this->work->update(['payable_quantity' => 11]);
    $owner = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$owner->html());
    $xpath = new DOMXPath($document);
    $table = $xpath->query('//*[contains(@class, "renome-lab-main-works")]//table')->item(0);
    $headers = $xpath->query('./thead/tr/th', $table);
    expect($headers->item(3)->getAttribute('style'))->toContain('10%');
    foreach ($headers as $header) {
        expect($header->textContent)->not->toContain('Payable', __('lab.payable_quantity'));
    }
    $rows = $xpath->query('./tbody/tr', $table);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        $cells = $xpath->query('./td', $row);
        expect($cells)->toHaveCount(7);
        $quantity = $cells->item(3);
        expect($xpath->query('.//input', $quantity))->toHaveCount(1);
    }
    $section = $xpath->query('//*[@data-lab-payable-adjustment]')->item(0);
    expect($section)->not->toBeNull();
    expect($section->getAttribute('style'))->toContain('width:fit-content', 'max-width:100%')
        ->and(substr_count($section->textContent, __('lab.payable_default')))->toBe(1);
    expect($xpath->query('.//input[@type="number"]', $section))->toHaveCount(2)
        ->and($section->textContent)->toContain('PMMA', 'Zircon', __('lab.produced_quantity'));
    foreach ($xpath->query('.//input[@type="number"]', $section) as $input) {
        expect($input->getAttribute('aria-label'))->toBe(__('lab.payable_quantity'))
            ->and($xpath->query('ancestor::*[contains(@style, "width:80px")]', $input))->toHaveCount(1);
    }
    expect($xpath->query('preceding::*[contains(@class, "renome-lab-main-works")]', $section)->length)->toBeGreaterThan(0)
        ->and($xpath->query('following::*[contains(@class, "renome-lab-additional")]', $section)->length)->toBeGreaterThan(0);
    $headerCount = $headers->length;
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $other = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$other->html());
    $xpath = new DOMXPath($document);
    $table = $xpath->query('//*[contains(@class, "renome-lab-main-works")]//table')->item(0);
    expect($xpath->query('./thead/tr/th', $table))->toHaveCount($headerCount)
        ->and($xpath->query('//*[@data-lab-payable-adjustment]'))->toHaveCount(0);
    foreach ($xpath->query('./tbody/tr', $table) as $row) {
        $cells = $xpath->query('./td', $row);
        expect($cells)->toHaveCount(7)->and($xpath->query('.//input', $cells->item(3)))->toHaveCount(1);
    }
});
