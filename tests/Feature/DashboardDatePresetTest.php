<?php

use App\Filament\Pages\Dashboard;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard preserves preset keys and filters the inclusive last fourteen days', function () {
    $this->travelTo(now()->setDate(2026, 9, 22)->setTime(12, 0));
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Range', 'last_name' => 'Test']);
    $visits = collect([0, 13, 14])->map(fn ($days) => Visit::create([
        'patient_id' => $patient->id, 'visit_date' => today()->subDays($days), 'visit_type' => 'treatment',
    ]));
    $page = Livewire::test(Dashboard::class);
    $presets = $page->instance()->getTable()->getHeader()->getData()['datePresets'];
    expect(array_keys($presets))->toBe(['today', 7, 14, 'month', '3months', '6months', 'year', 'all'])
        ->and($presets[14])->toBe(['from' => '2026-09-09', 'until' => '2026-09-22'])
        ->and($presets[7])->toBe(['from' => '2026-09-16', 'until' => '2026-09-22']);
    $page->assertSeeHtml("applyPreset('14')")
        ->assertSeeHtml("isPresetActive('14')")
        ->filterTable('visit_date', $presets[14])
        ->assertCanSeeTableRecords($visits->take(2))
        ->assertCanNotSeeTableRecords($visits->slice(2));
    $page->filterTable('visit_date', ['from' => '2026-09-08', 'until' => '2026-09-08'])
        ->assertCanSeeTableRecords($visits->slice(2))
        ->assertCanNotSeeTableRecords($visits->take(2));
});
