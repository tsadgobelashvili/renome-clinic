<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->foreignId('doctor_id')->nullable()->change();
        });

        Schema::create('patient_doctor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['patient_id', 'doctor_id']);
            $table->index(['patient_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_doctor');

        if (! DB::table('visits')->whereNull('doctor_id')->exists()) {
            Schema::table('visits', function (Blueprint $table): void {
                $table->foreignId('doctor_id')->nullable(false)->change();
            });
        }
    }
};
