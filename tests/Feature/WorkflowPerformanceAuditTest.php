<?php

use App\Filament\Pages\Bank;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DoctorCompensation;
use App\Filament\Pages\Finance;
use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\PartnerPatients\Pages\ListPartnerPatients;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('main workflow query audit with representative list sizes', function (int $size) {
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
    $this->actingAs(User::factory()->create());
    $treatment = TreatmentCase::create(['name' => 'Audit', 'category' => 'tomography', 'is_active' => true]);
    for ($i = 0; $i < $size; $i++) {
        $doctor = Doctor::create(['first_name' => 'Audit', 'last_name' => (string) $i, 'is_active' => true, 'compensation_percentage' => 40]);
        $patient = Patient::create(['first_name' => 'Audit', 'last_name' => (string) $i]);
        $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 100]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->id, 'quantity' => 1, 'unit_price' => 100]);
        $visit->payments()->create(['amount' => 100, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
        $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'case_date' => today(), 'source' => 'clinic']);
        $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);
        $partner = Patient::create(['first_name' => 'Partner', 'last_name' => (string) $i, 'patient_group_id' => PatientGroup::israelPartnerId()]);
        $partner->partnerPayments()->create(['amount' => 50, 'currency' => 'USD', 'paid_at' => today(), 'payment_method' => 'cash']);
        $employee = Employee::create(['first_name' => 'Staff', 'last_name' => (string) $i, 'is_active' => true,
            'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id, 'salary_payout_day' => 16]);
        $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL',
            'net_amount' => 1000, 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-09-01', 'is_active' => true]);
        DB::table('bank_transactions')->insert(['bank' => 'BOG', 'transaction_date' => now(), 'direction' => 'outflow',
            'amount' => 1, 'currency' => 'GEL', 'operation_type' => 'COM', 'operation_id' => 'audit-'.$i,
            'deduplication_key' => hash('sha256', 'audit-'.$i), 'fingerprint' => hash('sha256', 'audit-'.$i), 'source' => 'import']);
    }
    $results = [];
    foreach ([
        'Dashboard' => Dashboard::class, 'Visits' => ListVisits::class, 'Patients' => ListPatients::class,
        'Finance' => Finance::class, 'Bank' => Bank::class, 'Payroll' => DoctorCompensation::class,
        'Statistics' => FinanceReports::class, 'Laboratory' => ListLabCases::class, 'PartnerPatients' => ListPartnerPatients::class,
    ] as $label => $page) {
        foreach (['mount', 'refresh'] as $phase) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                if ($phase === 'mount') {
                    $component = Livewire::test($page)->assertSuccessful();
                } else {
                    $component->call('$refresh')->assertSuccessful();
                }
                $queries = collect(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $results[$label][$phase] = [
                'count' => $queries->count(),
                'sql_ms' => round($queries->sum('time'), 2),
                'repeated' => $queries->groupBy('query')->map->count()->sortDesc()->take(8)->all(),
                'slowest_sql' => $queries->sortByDesc('time')->first()['query'] ?? null,
            ];
        }
    }
    file_put_contents(storage_path('app/performance-pages-'.$size.'.json'), json_encode($results, JSON_PRETTY_PRINT));
})->with([1, 10]);
