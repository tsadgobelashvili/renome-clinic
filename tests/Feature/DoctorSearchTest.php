<?php

use App\Models\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('doctor search supports first last and either full name order', function (string $search) {
    $doctor = Doctor::create([
        'first_name' => 'ნოდარ',
        'last_name' => 'ელიშაკოვი',
        'is_active' => true,
    ]);

    expect(Doctor::query()->searchByName($search)->whereKey($doctor->getKey())->exists())->toBeTrue();
})->with([
    'first name' => 'ნოდარ',
    'last name' => 'ელიშაკოვი',
    'first and last name' => 'ნოდარ ელიშაკოვი',
    'last and first name' => 'ელიშაკოვი ნოდარ',
]);

test('doctor search is case insensitive', function () {
    $doctor = Doctor::create([
        'first_name' => 'Nodar',
        'last_name' => 'Elishakovi',
        'is_active' => true,
    ]);

    expect(Doctor::query()->searchByName('NODAR ELISHAKOVI')->whereKey($doctor->getKey())->exists())->toBeTrue();
});
