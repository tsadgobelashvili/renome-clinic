<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\TreatmentEstimates\Pages\ViewTreatmentEstimate;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    app()->setLocale('ka');
    $this->patient = Patient::create(['first_name' => 'Plan', 'last_name' => 'Patient']);
    $this->visit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation']);
    $this->plan = TreatmentEstimate::create(['patient_id' => $this->patient->id, 'visit_id' => $this->visit->id, 'estimate_date' => today()]);
    $option = $this->plan->options()->create(['name' => 'Visit-specific option']);
    $stage = $option->stages()->create(['name' => 'Visit-specific stage', 'sort_order' => 1]);
    $option->items()->create(['treatment_estimate_stage_id' => $stage->id, 'description' => 'Visit-specific procedure', 'quantity' => 2, 'unit_price' => 120]);
});

test('dashboard keeps consultation and imaging badges beside the visit plan action', function () {
    foreach (['3D CT', 'Panorama'] as $name) {
        $service = TreatmentCase::firstOrCreate(['name' => $name], ['category' => 'tomography']);
        $this->visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 2, 'unit_price' => 60]);
    }
    $page = Livewire::test(Dashboard::class)->assertTableActionVisible('treatmentPlan', $this->visit);
    $column = $page->instance()->getTable()->getColumn('treatment_cases_summary')->record($this->visit->fresh());
    expect($column->toHtml())->toContain('3D CT x2', 'Panorama x2', 'renome-treatment-consultation', 'კონსულტაცია', 'გეგმა', "mountTableAction('treatmentPlan'");

    // Inspect the full Filament cell: column-only HTML misses its row-action wrapper.
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    $xpath = new DOMXPath($document);
    $badges = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " renome-visit-badges ")]')->item(0);
    expect($badges)->not->toBeNull()
        ->and($xpath->query('ancestor::button', $badges)->length)->toBe(0)
        ->and($xpath->query('.//button[contains(@*[name() = "wire:click.stop"], "treatmentPlan")]', $badges)->length)->toBe(1);
});

test('dashboard does not show a plan from another visit or an unlinked patient plan', function () {
    $otherVisit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation']);
    TreatmentEstimate::create(['patient_id' => $this->patient->id, 'estimate_date' => today()]);
    $page = Livewire::test(Dashboard::class)->assertTableActionHidden('treatmentPlan', $otherVisit);
    $column = $page->instance()->getTable()->getColumn('treatment_cases_summary')->record($otherVisit->fresh());
    expect($column->toHtml())->toContain('კონსულტაცია')->not->toContain("mountTableAction('treatmentPlan'");
});

test('dashboard plan modal opens the exact visit plan and reuses its normal exports', function () {
    $otherVisit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation']);
    $otherPlan = TreatmentEstimate::create(['patient_id' => $this->patient->id, 'visit_id' => $otherVisit->id, 'estimate_date' => today()]);
    $otherPlan->options()->create(['name' => 'Wrong latest plan']);

    $page = Livewire::test(Dashboard::class)
        ->call('mountTableAction', 'treatmentPlan', (string) $this->visit->id, ['estimate' => $this->plan->id])
        ->assertMountedActionModalSee(['Visit-specific option', 'Visit-specific procedure', '240.00', today()->format('d.m.Y')])
        ->assertMountedActionModalDontSee('Wrong latest plan')
        ->assertMountedActionModalDontSee(['დაგეგმილი', 'შესრულებული', 'გადახდილი', 'დარჩენილი'])
        ->assertMountedActionModalSee(['პაციენტი', 'თარიღი', 'ექიმი', 'ეტაპის ჯამი', 'საბოლოო ჯამი'])
        ->assertMountedActionModalSeeHtml('renome-plan-document__table')
        ->assertNoRedirect();
    $normal = Livewire::test(ViewTreatmentEstimate::class, ['record' => $this->plan->id]);
    foreach (['pdf', 'word'] as $format) {
        $url = route('treatment-estimates.'.$format, ['patient' => $this->patient->id, 'estimate' => $this->plan->id]);
        $selectorUrl = route('treatment-estimates.export', ['patient' => $this->patient->id, 'estimate' => $this->plan->id, 'format' => $format]);
        $normal->assertActionHasUrl($format, $selectorUrl);
        $page->assertMountedActionModalSeeHtml(e($selectorUrl));
        $this->get($selectorUrl)->assertOk()->assertSee(['ქართული', 'English', 'Русский']);
        $response = $this->get($url)->assertOk();
        if ($format === 'pdf') {
            $response->assertHeader('content-type', 'application/pdf');
            expect($response->getContent())->toStartWith('%PDF-');
        } else {
            $response->assertDownload('treatment-estimate-'.$this->plan->id.'.docx');
            $archive = new ZipArchive;
            $path = $response->baseResponse->getFile()->getPathname();
            expect($archive->open($path))->toBeTrue();
            expect($archive->getFromName('word/document.xml'))->toContain('Visit-specific procedure', '240.00');
            $archive->close();
            unlink($path);
        }
    }
});

test('dashboard rejects a plan id belonging to another visit of the same patient', function () {
    $otherPlan = TreatmentEstimate::create(['patient_id' => $this->patient->id, 'estimate_date' => today()]);
    Livewire::test(Dashboard::class)
        ->call('mountTableAction', 'treatmentPlan', (string) $this->visit->id, ['estimate' => $otherPlan->id]);
})->throws(ModelNotFoundException::class);

test('dashboard consultation creation links its pending plan to the saved visit', function () {
    $this->plan->update(['visit_id' => null]);
    $visit = VisitForm::createDashboardVisit([
        'patient_id' => $this->patient->id, 'doctor_id' => null, 'visit_date' => today()->toDateString(),
        'visit_type' => 'consultation', 'treatment_estimate_id' => $this->plan->id,
        'treatmentCaseItems' => [],
    ]);
    expect($this->plan->fresh()->visit_id)->toBe($visit->id);
    Livewire::test(Dashboard::class)->assertTableActionVisible('treatmentPlan', $visit);
});
