<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('removing consultation confirms then changes classification only', function (string $role, bool $clinicalWork) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $visit = Visit::create([
        'patient_id' => Patient::create(['first_name' => 'Removal', 'last_name' => 'Test'])->id,
        'visit_date' => today(), 'visit_type' => 'consultation', 'consultation_fee' => 25,
    ]);
    $ct = TreatmentCase::where('name', '3D CT')->sole();
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $ct->id, 'quantity' => 2, 'unit_price' => 100]);
    if ($clinicalWork) {
        $service = TreatmentCase::create(['name' => 'Historical work', 'category' => 'therapy']);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 50]);
    }
    Payment::create(['visit_id' => $visit->id, 'amount' => 50, 'currency' => 'GEL', 'payment_method' => 'cash', 'payment_date' => today()]);
    $before = $visit->fresh()->getAttributes();
    $items = $visit->treatmentCaseItems()->get()->toArray();
    $payments = $visit->payments()->with('splits')->get()->toArray();

    $page = Livewire::test(EditVisit::class, ['record' => $visit->id])
        ->assertActionVisible('removeConsultation')->mountAction('removeConsultation');
    expect($visit->fresh()->getAttributes())->toBe($before);
    $actions = $page->instance()->form->getComponents(withHidden: true)[2];
    expect($actions->isHidden())->toBeFalse();
    expect($actions->toHtml())->toContain('Remove consultation');
    $page->callMountedAction()->assertHasNoActionErrors();
    expect($visit->fresh()->visit_type)->toBe($clinicalWork ? 'treatment' : 'diagnostic');
    expect(collect($visit->fresh()->getAttributes())->except(['visit_type', 'updated_at'])->all())
        ->toBe(collect($before)->except(['visit_type', 'updated_at'])->all());
    expect($visit->treatmentCaseItems()->get()->toArray())->toBe($items)
        ->and($visit->payments()->with('splits')->get()->toArray())->toBe($payments);
    Livewire::test(EditVisit::class, ['record' => $visit->id])->assertActionHidden('removeConsultation');
    $list = Livewire::test(ListVisits::class);
    $column = $list->instance()->getTable()->getColumn('treatment_cases_summary')->record($visit->fresh()->load('treatmentCaseItems.treatmentCase'));
    expect($column->getState())->toContain('3D CT x2')->not->toContain('renome-treatment-consultation');
    // Statistics is owner-only; use that same cohort after the operational action.
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 0);
})->with([
    'owner diagnostic' => [User::ROLE_OWNER, false],
    'administrator diagnostic' => [User::ROLE_ADMINISTRATOR, false],
    'historical mixed work' => [User::ROLE_OWNER, true],
]);

test('remove consultation is hidden for other visit classifications', function (string $type) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = Visit::create(['patient_id' => Patient::create(['first_name' => 'Other', 'last_name' => 'Visit'])->id, 'visit_date' => today(), 'visit_type' => $type]);
    Livewire::test(EditVisit::class, ['record' => $visit->id])->assertActionHidden('removeConsultation');
})->with(['treatment', 'diagnostic']);
