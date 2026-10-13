<?php

use App\Filament\Pages\FinanceReports;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('every statistics group renders only its unchanged summary totals', function (string $group) {
    $this->travelTo('2026-10-13 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Summary', 'last_name' => 'Doctor', 'is_active' => true]);
    $category = TreatmentCase::STATISTICS_GROUP_CATEGORIES[$group] ?? 'other';
    $visits = [];
    foreach ([2, 3] as $index => $quantity) {
        $patient = Patient::create(['first_name' => 'Summary', 'last_name' => 'Patient '.$index]);
        $treatment = TreatmentCase::create(['name' => 'Catalog detail secret '.$index,
            'category' => $category, 'statistics_group' => $group, 'is_active' => true]);
        $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'visit_date' => today(), 'visit_type' => 'treatment', 'currency' => 'GEL', 'total_price' => $quantity * 100]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->id,
            'comment' => 'Visit detail secret '.$index, 'quantity' => $quantity, 'unit_price' => 100, 'currency' => 'GEL']);
        $visit->payments()->create(['patient_id' => $patient->id, 'amount' => $quantity * 100,
            'currency' => 'GEL', 'payment_method' => 'cash', 'payment_date' => today()]);
        $visits[] = $visit;
    }

    $page = Livewire::test(FinanceReports::class)->set('source', 'clinic')
        ->set('dateFrom', '2026-10-01')->set('dateUntil', '2026-10-13')->call('selectSectionTab', 'doctors');
    $page->assertViewHas('doctorStatistics', function (array $statistics) use ($group): bool {
        $summary = collect($statistics['treatmentGroups'])->firstWhere('key', $group);
        expect($summary['label'])->toBe(TreatmentCase::statisticsGroupLabels()[$group])
            ->and($summary['patients'])->toBe(2)->and($summary['quantity'])->toBe(5)
            ->and($summary['amount'])->toBe(500.0)->and($summary)->not->toHaveKey('breakdown');

        return true;
    });
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    $xpath = new DOMXPath($document);
    $row = $xpath->query('//*[@data-statistics-group-row="'.$category.'-'.$group.'"]')->item(0)
        ?? $xpath->query('//*[@data-statistics-category-row="other"]')->item(0);
    expect($row)->not->toBeNull();
    $cells = $xpath->query('./td', $row);
    $label = $group === 'other' ? $xpath->query('.//span', $cells->item(0))->item(0)->textContent : $cells->item(0)->textContent;
    expect($cells)->toHaveCount(4)
        ->and(trim($label))->toBe(TreatmentCase::statisticsGroupLabels()[$group])
        ->and(trim($cells->item(1)->textContent))->toBe('2')
        ->and(trim($cells->item(2)->textContent))->toBe('5')
        ->and(trim($cells->item(3)->textContent))->toBe(Currency::format(500, 'GEL'))
        ->and($row->textContent)->not->toContain('Catalog detail secret', 'Visit detail secret');
    $page->assertDontSee('Catalog detail secret')->assertDontSee('Visit detail secret');
    expect(Visit::where('doctor_id', $doctor->id)->count())->toBe(2);
    foreach ($visits as $index => $visit) {
        expect($visit->treatmentCaseItems()->sole()->comment)->toBe('Visit detail secret '.$index);
    }
})->with(array_keys(TreatmentCase::STATISTICS_GROUPS));
