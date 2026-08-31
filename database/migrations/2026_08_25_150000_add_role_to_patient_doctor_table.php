<?php

use App\Services\PatientDoctorAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_doctor', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('doctor_id');
            $table->string('assignment_source', 10)->default('manual')->after('role');
        });

        app(PatientDoctorAssignment::class)->backfillExistingVisits();
    }

    public function down(): void
    {
        Schema::table('patient_doctor', function (Blueprint $table): void {
            $table->dropColumn(['role', 'assignment_source']);
        });
    }
};
