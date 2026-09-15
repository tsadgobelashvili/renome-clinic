<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('patient visit and payment indexes cover recurring lookup prefixes and migrate reversibly', function () {
    $patient = \App\Models\Patient::create(['first_name' => 'Index', 'last_name' => 'Audit']);
    $visit = \App\Models\Visit::create(['patient_id' => $patient->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 50]);
    $visit->payments()->create(['amount' => 20, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    $migration = require database_path('migrations/2026_09_15_120000_add_patient_visit_payment_lookup_indexes.php');
    $counts = [DB::table('visits')->count(), DB::table('payments')->count()];
    expect(Schema::hasIndex('visits', ['patient_id', 'visit_date']))->toBeTrue()
        ->and(Schema::hasIndex('payments', ['visit_id', 'currency']))->toBeTrue();
    $migration->up();
    $migration->down();
    expect(Schema::hasIndex('visits', 'visits_patient_date_lookup_idx'))->toBeFalse()
        ->and(Schema::hasIndex('payments', 'payments_visit_currency_lookup_idx'))->toBeFalse();
    $migration->up();
    expect(Schema::hasIndex('visits', ['patient_id', 'visit_date']))->toBeTrue()
        ->and(Schema::hasIndex('payments', ['visit_id', 'currency']))->toBeTrue()
        ->and([DB::table('visits')->count(), DB::table('payments')->count()])->toBe($counts);
});

test('index pass does not duplicate equivalent deployment indexes or drop them on rollback', function () {
    $migration = require database_path('migrations/2026_09_15_120000_add_patient_visit_payment_lookup_indexes.php');
    $migration->down();
    Schema::table('visits', fn (Blueprint $table) => $table->index(['patient_id', 'visit_date'], 'existing_patient_date'));
    Schema::table('payments', fn (Blueprint $table) => $table->index(['visit_id', 'currency'], 'existing_visit_currency'));
    $migration->up();
    expect(Schema::hasIndex('visits', 'visits_patient_date_lookup_idx'))->toBeFalse()
        ->and(Schema::hasIndex('payments', 'payments_visit_currency_lookup_idx'))->toBeFalse();
    $migration->down();
    expect(Schema::hasIndex('visits', 'existing_patient_date'))->toBeTrue()
        ->and(Schema::hasIndex('payments', 'existing_visit_currency'))->toBeTrue();
});
