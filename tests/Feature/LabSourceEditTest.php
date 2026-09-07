<?php

use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\IsraeliLabSalaryItems;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('lab period presets and clearing preserve source and doctor filters', function () {
    $page = Livewire::test(ListLabCases::class)->filterTable('toolbar', ['source' => 'clinic', 'doctor_id' => $this->doctor->id])
        ->call('applyDatePeriod', '14');
    expect(substr($page->get('tableFilters.toolbar.from'), 0, 10))->toBe(today()->subDays(13)->toDateString());
    $page->call('applyDatePeriod', 'month');
    expect(substr($page->get('tableFilters.toolbar.from'), 0, 10))->toBe(today()->subMonthNoOverflow()->toDateString());
    $page->call('applyDatePeriod', 'all');
    expect($page->get('tableFilters.toolbar.from'))->toBeNull()->and($page->get('tableFilters.toolbar.until'))->toBeNull()
        ->and($page->get('tableFilters.toolbar.source'))->toBe('clinic')
        ->and((int) $page->get('tableFilters.toolbar.doctor_id'))->toBe($this->doctor->id);
});

test('lab language action toggles both languages without a form', function () {
    auth()->user()->update(['locale' => 'ka']);
    Livewire::test(ListLabCases::class)->callAction('language')->assertRedirect();
    expect(auth()->user()->fresh()->locale)->toBe('en');
    Livewire::test(ListLabCases::class)->callAction('language')->assertRedirect();
    expect(auth()->user()->fresh()->locale)->toBe('ka');
});

test('lab toolbar defaults to all sources and the last ten calendar days', function () {
    $old = $this->case->replicate();
    $old->case_date = today()->subDays(10);
    $old->save();
    $boundary = $this->case->replicate();
    $boundary->fill(['case_date' => today()->subDays(9), 'source' => 'external'])->save();
    $page = Livewire::test(ListLabCases::class)->assertCanSeeTableRecords([$this->case, $boundary])
        ->assertCanNotSeeTableRecords([$old]);
    expect($page->get('tableFilters.toolbar.source'))->toBe('all')
        ->and(substr($page->get('tableFilters.toolbar.from'), 0, 10))->toBe(today()->subDays(9)->toDateString())
        ->and(substr($page->get('tableFilters.toolbar.until'), 0, 10))->toBe(today()->toDateString());
    $page->filterTable('toolbar', ['source' => 'clinic'])->assertCanNotSeeTableRecords([$boundary]);
    $page->filterTable('toolbar', ['source' => 'all'])->assertCanSeeTableRecords([$boundary]);
});

test('inline doctor source and inclusive dates combine with existing search', function () {
    $this->case->update(['case_date' => '2026-09-05']);
    $other = $this->case->replicate();
    $other->fill(['case_date' => '2026-09-06', 'source' => 'external'])->save();
    $page = Livewire::test(ListLabCases::class)->assertSeeHtml('renome-lab-filters')
        ->filterTable('toolbar', ['doctor_id' => $this->doctor->id, 'source' => 'clinic', 'from' => '2026-09-05', 'until' => '2026-09-05'])
        ->searchTable('Source')->assertCanSeeTableRecords([$this->case])->assertCanNotSeeTableRecords([$other]);
    $page->searchTable('No matching patient')->assertCanNotSeeTableRecords([$this->case]);
    $page->searchTable('')->filterTable('toolbar', ['doctor_id' => null, 'source' => null, 'from' => null, 'until' => null])
        ->assertCanSeeTableRecords([$this->case, $other]);
    expect($page->instance()->getTabs())->toBeEmpty()
        ->and($page->instance()->getTable()->getFiltersLayout())->toBe(FiltersLayout::Hidden);
});

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Source', 'last_name' => 'Patient']);
    $this->doctor = Doctor::create(['first_name' => 'Source', 'last_name' => 'Doctor', 'is_active' => true, 'israeli_lab_zircon_rate' => 100]);
    $this->case = LabCase::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'case_date' => today(), 'source' => 'clinic']);
    $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
});

test('owner edits saved lab source without changing the patient or other work fields', function (string $source) {
    $patientBefore = $this->patient->fresh()->getAttributes();
    $before = $this->case->fresh()->only(['patient_id', 'doctor_id', 'case_date', 'notes']);
    Livewire::test(EditLabCase::class, ['record' => $this->case->id])->fillForm(['source' => $source])
        ->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe($source)
        ->and($this->patient->fresh()->getAttributes())->toBe($patientBefore)
        ->and($this->case->fresh()->only(['patient_id', 'doctor_id', 'case_date', 'notes']))
        ->toEqual($before);
    Livewire::test(ListLabCases::class)->filterTable('toolbar', ['source' => $source])->assertCanSeeTableRecords([$this->case]);
    if ($source !== 'clinic') {
        Livewire::test(ListLabCases::class)->filterTable('toolbar', ['source' => 'clinic'])->assertCanNotSeeTableRecords([$this->case]);
    }
})->with(['israeli', 'external', 'clinic']);

test('salary eligibility follows saved source independently of live patient group', function () {
    $service = app(IsraeliLabSalaryItems::class);
    expect($service->eligible($this->doctor))->toBeEmpty();
    $this->case->update(['source' => 'israeli']);
    expect($service->eligible($this->doctor))->toHaveCount(1);
    $this->patient->update(['patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->case->update(['source' => 'clinic']);
    expect($service->eligible($this->doctor))->toBeEmpty();
    $this->case->update(['source' => 'external']);
    expect($service->eligible($this->doctor))->toBeEmpty();
});

test('invalid source is rejected by backend validation', function () {
    expect(fn () => $this->case->update(['source' => 'invalid']))->toThrow(ValidationException::class);
    expect($this->case->fresh()->source)->toBe('clinic');
});

test('users without lab access cannot edit source', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->case->update(['created_by' => auth()->id()]);
    Livewire::test(EditLabCase::class, ['record' => $this->case->id])->assertForbidden();
    expect($this->case->fresh()->source)->toBe('clinic');
});

test('lab technician can correct source on a record they are allowed to edit', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $this->case->update(['created_by' => auth()->id()]);
    Livewire::test(EditLabCase::class, ['record' => $this->case->id])->fillForm(['source' => 'external'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('external');
});
