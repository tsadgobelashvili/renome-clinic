<?php

use App\Filament\Pages\FinanceReports;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\DatabaseSafety;

// Build a new in-memory schema only. No RefreshDatabase, reset, fresh or seed.
beforeEach(function () {
    DatabaseSafety::assertInMemory(DB::connection()->getConfig());
    Http::preventStrayRequests();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->page = new FinanceReports;
    $this->page->dateFrom = '2026-09-01';
    $this->page->dateUntil = '2026-09-30';
    $this->page->currency = 'GEL';
    $this->page->source = 'clinic';
    $this->statistics = fn () => (new ReflectionMethod(FinanceReports::class, 'doctorStatistics'))->invoke($this->page);
});

function paidStatisticsVisit(string $name = 'Doctor'): Visit
{
    $doctor = Doctor::create(['first_name' => $name, 'last_name' => 'Statistics', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Income', 'last_name' => $name]);
    $visit = Visit::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'visit_date' => '2026-09-10', 'visit_type' => 'treatment', 'currency' => 'GEL', 'total_price' => 500]);
    foreach (['surgery' => 300, 'therapy' => 200] as $category => $amount) {
        $treatment = TreatmentCase::create(['name' => $name.' '.$category, 'category' => $category, 'default_price' => $amount, 'is_active' => true]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->id, 'quantity' => 1, 'unit_price' => $amount, 'currency' => 'GEL']);
    }

    return $visit->fresh();
}

function paidStatisticsReceipt(Visit $visit, float $amount, array $extra = []): int
{
    return DB::table('payments')->insertGetId(['visit_id' => $visit->id, 'amount' => $amount, 'currency' => 'GEL',
        'payment_date' => '2026-10-01', 'payment_method' => 'cash', ...$extra]);
}

test('doctor income reflects receipts while patients and performed quantities stay unchanged', function (array $payments, float $expected) {
    $visit = paidStatisticsVisit();
    $this->page->selectedDoctorId = $visit->doctor_id;
    foreach ($payments as $amount) {
        $id = paidStatisticsReceipt($visit, $amount);
        // Split-tender rows must not multiply the already-converted parent amount.
        DB::table('payment_splits')->insert([
            ['payment_id' => $id, 'amount' => $amount / 2, 'currency' => 'GEL', 'payment_method' => 'cash'],
            ['payment_id' => $id, 'amount' => $amount / 2, 'currency' => 'GEL', 'payment_method' => 'card'],
        ]);
    }
    $before = DB::table('payments')->get()->toJson();
    $stats = ($this->statistics)();
    expect($stats['totalRevenue'])->toBe($expected)
        ->and($stats['totalPatients'])->toBe(1)
        ->and(collect($stats['categories'])->sum('procedures'))->toBe(2)
        ->and(collect($stats['categories'])->sum('revenue'))->toBe($expected)
        ->and(collect($stats['treatmentGroups'])->sum('amount'))->toBe($expected)
        ->and(collect($stats['treatmentGroups'])->sum('quantity'))->toBe(2)
        ->and($stats['doctors'][0]['revenue'])->toBe($expected)
        ->and(collect($stats['details'][$visit->doctor_id]['procedures'])->sum('revenue'))->toBe($expected)
        ->and(array_sum($stats['details'][$visit->doctor_id]['dynamics']['series'][0]['data']))->toEqual($expected)
        ->and(DB::table('payments')->get()->toJson())->toBe($before);
})->with([[[], 0.0], [[200], 200.0], [[500], 500.0], [[50, 150], 200.0]]);

test('doctor income preserves discount limits removed payments currency and visit date filters', function () {
    $visit = paidStatisticsVisit();
    DB::table('visits')->where('id', $visit->id)->update(['discount_amount' => 100]);
    paidStatisticsReceipt($visit, 500);
    paidStatisticsReceipt($visit, 100, ['deleted_at' => now()]);
    paidStatisticsReceipt($visit, 100, ['currency' => 'USD']);
    $other = paidStatisticsVisit('Other');
    paidStatisticsReceipt($other, 125);
    $outside = paidStatisticsVisit('Outside');
    DB::table('visits')->where('id', $outside->id)->update(['visit_date' => '2026-08-01']);
    paidStatisticsReceipt($outside, 500);
    $stats = ($this->statistics)();
    expect($stats['totalRevenue'])->toBe(525.0)
        ->and(collect($stats['doctors'])->firstWhere('id', $visit->doctor_id)['revenue'])->toBe(400.0)
        ->and(collect($stats['doctors'])->firstWhere('id', $other->doctor_id)['revenue'])->toBe(125.0)
        ->and(collect($stats['treatmentGroups'])->sum('amount'))->toBe(525.0);
    DB::table('payments')->where('visit_id', $visit->id)->update(['deleted_at' => now()]);
    expect(($this->statistics)()['totalRevenue'])->toBe(125.0);
});

test('paid allocation preserves currency conversion and category exclusions without extra per visit queries', function () {
    $visit = paidStatisticsVisit();
    $item = $visit->treatmentCaseItems()->orderBy('id')->firstOrFail();
    DB::table('visit_treatment_cases')->where('id', $item->id)->update(['unit_price' => 120, 'currency' => 'USD', 'exchange_rate' => 2.5]);
    $scan = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'default_price' => 100, 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $scan->id, 'quantity' => 1, 'unit_price' => 100, 'currency' => 'GEL']);
    paidStatisticsReceipt($visit, 240);
    $stats = ($this->statistics)();
    expect($stats['totalRevenue'])->toBe(200.0)
        ->and(collect($stats['categories'])->firstWhere('key', 'surgery')['revenue'])->toBe(120.0)
        ->and(collect($stats['categories'])->firstWhere('key', 'therapy')['revenue'])->toBe(80.0)
        ->and(collect($stats['categories'])->sum('procedures'))->toBe(2)
        ->and($stats['tomography']['ct']['quantity'])->toBe(1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    ($this->statistics)();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $second = paidStatisticsVisit('Second');
    paidStatisticsReceipt($second, 100);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $stats = ($this->statistics)();
    expect(count(DB::getQueryLog()))->toBe($queries)->and($stats['totalRevenue'])->toBe(300.0);
    DB::disableQueryLog();
});

test('partner income uses only linked active same currency payments', function () {
    $visit = paidStatisticsVisit('Partner');
    DB::table('patients')->where('id', $visit->patient_id)->update(['patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach ([[100, $visit->id, 'GEL', null], [75, $visit->id, 'GEL', null], [500, null, 'GEL', null],
        [400, $visit->id, 'GEL', now()], [300, $visit->id, 'USD', null]] as [$amount, $visitId, $currency, $deleted]) {
        DB::table('partner_patient_payments')->insert(['patient_id' => $visit->patient_id, 'visit_id' => $visitId,
            'amount' => $amount, 'currency' => $currency, 'paid_at' => '2026-09-15', 'payment_method' => 'cash', 'deleted_at' => $deleted]);
    }
    $this->page->source = 'partner';
    $stats = ($this->statistics)();
    expect($stats['totalRevenue'])->toBe(175.0)->and(collect($stats['treatmentGroups'])->sum('amount'))->toBe(175.0);
    DB::table('visits')->where('id', $visit->id)->update(['cancelled_at' => now()]);
    expect(($this->statistics)()['totalRevenue'])->toBe(0.0);
});
