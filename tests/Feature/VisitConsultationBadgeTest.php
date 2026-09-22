<?php

use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('visit summary adds the outlined consultation badge only alongside CT', function (string $type, string $service, bool $consultationItem, bool $expected) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = Visit::create([
        'patient_id' => Patient::create(['first_name' => 'Badge', 'last_name' => 'Patient'])->id,
        'doctor_id' => Doctor::create(['first_name' => 'Badge', 'last_name' => 'Doctor'])->id,
        'visit_date' => now(), 'visit_type' => $type,
    ]);
    if ($consultationItem) {
        $consultation = TreatmentCase::create(['name' => 'Consultation service', 'category' => 'consultation']);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $consultation->id, 'quantity' => 1, 'unit_price' => 10]);
    }
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => TreatmentCase::where('name', $service)->sole()->id,
        'quantity' => 2, 'unit_price' => 60,
    ]);
    $before = $visit->fresh()->getAttributes();
    $page = Livewire::test(ListVisits::class)->assertSuccessful();
    $column = $page->instance()->getTable()->getColumn('treatment_cases_summary')->record($visit->fresh()->load('treatmentCaseItems.treatmentCase'));
    $html = (string) $column->formatState($column->getState());
    expect($html)->toContain($service.' x2');
    if ($expected) {
        expect($html)->toContain('renome-treatment-pair', 'renome-treatment-consultation', 'კონსულტაცია');
        expect(strpos($html, '3D CT x2'))->toBeLessThan(strpos($html, 'კონსულტაცია'));
    } else {
        expect($html)->not->toContain('renome-treatment-consultation');
    }
    expect($visit->fresh()->getAttributes())->toBe($before);
})->with([
    'consultation with CT' => ['consultation', '3D CT', false, true],
    'CT without consultation' => ['treatment', '3D CT', false, false],
    'consultation without CT' => ['consultation', 'პანორამა', false, false],
    'procedure does not override treatment classification' => ['treatment', '3D CT', true, false],
]);
