<?php

use App\Models\Doctor;
use App\Services\LabPartyAutocomplete;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Lab selection uses all specialties plus legacy orthopedics and the explicit Otar exception', function () {
    $therapy = Doctor::create(['first_name' => 'Therapy', 'last_name' => 'Only', 'specialties' => ['therapy'], 'is_active' => true]);
    $mixed = Doctor::create(['first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე', 'specialties' => ['therapy', 'orthopedics'], 'is_active' => true]);
    $ortho = Doctor::create(['first_name' => 'Orthopedic', 'last_name' => 'Doctor', 'specialties' => ['orthopedics'], 'is_active' => true]);
    $legacy = Doctor::create(['first_name' => 'Legacy', 'last_name' => 'Doctor', 'specialty' => 'Prosthodontics', 'is_active' => true]);
    $unrelated = Doctor::create(['first_name' => 'Surgery', 'last_name' => 'Only', 'specialties' => ['surgery'], 'is_active' => true]);
    $inactive = Doctor::create(['first_name' => 'Inactive', 'last_name' => 'Doctor', 'specialties' => ['orthopedics'], 'is_active' => false]);
    $otar = Doctor::create(['first_name' => 'ოთარ', 'last_name' => 'ღრეული', 'specialties' => ['therapy'], 'is_active' => true]);
    $service = app(LabPartyAutocomplete::class);
    $options = $service->practitionerOptions();
    expect(array_keys($options))->toBe([$mixed->id, $ortho->id, $legacy->id, $otar->id]);
    foreach (['en', 'ka'] as $locale) {
        expect($service->doctorSuggestions('davit', $locale))->toBe(['Davit Chumburidze']);
        foreach ($service->doctorSuggestions('', $locale) as $name) {
            expect(preg_match('/\p{Georgian}/u', $name))->toBe(0);
        }
    }
    expect($service->practitionerFromLabel('Therapy Only')['doctor_id'])->toBeNull()
        ->and($mixed->fresh()->specialties)->toBe(['therapy', 'orthopedics']);
    $otar->update(['is_active' => false]);
    expect($service->practitionerOptions())->toHaveKey($otar->id);
});
