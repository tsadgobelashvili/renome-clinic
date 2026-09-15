<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_id')->nullable()->change();
            $table->string('external_patient_name')->nullable();
            $table->string('external_clinic_name')->nullable();
        });

        // Snapshot legacy external labels once, preserving existing links and supplied names.
        DB::table('lab_cases')->where('source', 'external')->orderBy('id')->chunkById(200, function ($cases): void {
            $patients = DB::table('patients')->whereIn('id', $cases->pluck('patient_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $doctors = DB::table('doctors')->whereIn('id', $cases->pluck('doctor_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $assistants = DB::table('employees')->whereIn('id', $cases->pluck('assistant_employee_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');
            foreach ($cases as $case) {
                $patient = $patients->get($case->patient_id);
                $doctor = $doctors->get($case->doctor_id) ?? $assistants->get($case->assistant_employee_id);
                DB::table('lab_cases')->where('id', $case->id)->update([
                    'external_patient_name' => $case->external_patient_name ?: ($patient ? trim($patient->first_name.' '.$patient->last_name) : null),
                    'external_doctor_name' => $case->external_doctor_name ?: ($doctor ? trim($doctor->first_name.' '.$doctor->last_name) : null),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('lab_cases')->whereNull('patient_id')->exists()) {
            throw new RuntimeException('External Lab cases exist without a core patient; rollback would invalidate them.');
        }
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_id')->nullable(false)->change();
            $table->dropColumn(['external_patient_name', 'external_clinic_name']);
        });
    }
};
