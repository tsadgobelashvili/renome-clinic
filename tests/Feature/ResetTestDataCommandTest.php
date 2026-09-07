<?php

use App\Models\Doctor;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TreatmentCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('reset command requires confirmation and leaves data untouched when declined', function () {
    $patient = Patient::create([
        'first_name' => 'Keep',
        'last_name' => 'Until Confirmed',
    ]);

    $this->artisan('renome:reset-test-data')
        ->expectsConfirmation('This will permanently delete all operational/test data. Continue?', 'no')
        ->expectsOutputToContain('Reset cancelled')
        ->assertSuccessful();

    expect(Patient::query()->whereKey($patient)->exists())->toBeTrue();
});

test('reset command deletes operational records and preserves configuration catalogs', function () {
    $user = User::factory()->create();
    $doctor = Doctor::create([
        'first_name' => 'Configured',
        'last_name' => 'Doctor',
        'phone' => '555000001',
        'email' => 'configured-doctor@example.test',
    ]);
    $service = TreatmentCase::create([
        'name' => 'Configured service',
        'category' => 'therapy',
        'default_price' => 100,
        'is_active' => true,
    ]);
    $group = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->firstOrFail();
    $category = ProductCategory::query()->firstOrFail();
    $product = Product::create([
        'name' => 'Configured product',
        'product_category_id' => $category->id,
        'selling_price' => 25,
        'is_active' => true,
    ]);
    $supplier = Supplier::create(['name' => 'Configured supplier']);

    $patient = Patient::create([
        'first_name' => 'Operational',
        'last_name' => 'Patient',
        'patient_group_id' => $group->id,
    ]);
    PartnerPatientPayment::create([
        'patient_id' => $patient->id,
        'amount' => 50,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);
    $purchase = Purchase::create([
        'purchase_date' => today(),
        'supplier_id' => $supplier->id,
        'created_by' => $user->id,
    ]);
    $purchase->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 10,
    ]);
    $labCaseId = DB::table('lab_cases')->insertGetId([
        'patient_id' => $patient->id,
        'case_date' => today(),
        'source' => 'israeli',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('lab_main_works')->insert([
        'lab_case_id' => $labCaseId,
        'material' => 'zircon',
        'quantity' => 1,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('partner_finance_transactions')->insert([
        'type' => 'expense',
        'transacted_at' => now(),
        'amount' => 15,
        'currency' => 'GEL',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('renome:reset-test-data')
        ->expectsConfirmation('This will permanently delete all operational/test data. Continue?', 'yes')
        ->expectsOutputToContain('Operational/test data reset completed')
        ->expectsOutputToContain('lab_main_works')
        ->assertSuccessful();

    expect(Patient::query()->count())->toBe(0)
        ->and(PartnerPatientPayment::query()->count())->toBe(0)
        ->and(Purchase::query()->count())->toBe(0)
        ->and(DB::table('purchase_items')->count())->toBe(0)
        ->and(DB::table('lab_main_works')->count())->toBe(0)
        ->and(DB::table('partner_finance_transactions')->count())->toBe(0)
        ->and(User::query()->whereKey($user)->exists())->toBeTrue()
        ->and(Doctor::query()->whereKey($doctor)->exists())->toBeTrue()
        ->and(TreatmentCase::query()->whereKey($service)->exists())->toBeTrue()
        ->and(PatientGroup::query()->whereKey($group)->exists())->toBeTrue()
        ->and(ProductCategory::query()->whereKey($category)->exists())->toBeTrue()
        ->and(Product::query()->whereKey($product)->exists())->toBeTrue()
        ->and(Supplier::query()->whereKey($supplier)->exists())->toBeTrue();
});
