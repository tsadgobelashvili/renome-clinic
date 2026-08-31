<?php

use App\Services\PatientDoctorAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('patient_doctor')->whereNull('role')->orderBy('id')->each(function (object $relation): void {
            $specialty = DB::table('doctors')->where('id', $relation->doctor_id)->value('specialty');
            DB::table('patient_doctor')->where('id', $relation->id)->update([
                'role' => filled($specialty) ? trim((string) $specialty) : 'ექიმი',
            ]);
        });

        Schema::table('patient_doctor', function (Blueprint $table): void {
            $table->dropUnique(['patient_id', 'doctor_id']);
            $table->string('role')->nullable(false)->change();
            $table->unique(['patient_id', 'doctor_id', 'role']);
        });

        app(PatientDoctorAssignment::class)->backfillExistingVisits();
    }

    public function down(): void
    {
        Schema::table('patient_doctor', function (Blueprint $table): void {
            $table->dropUnique(['patient_id', 'doctor_id', 'role']);
            $table->index(['patient_id', 'doctor_id']);
            $table->string('role')->nullable()->change();
        });
    }
};
