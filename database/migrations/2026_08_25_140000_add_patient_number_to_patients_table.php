<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_number')->nullable()->after('id');
        });

        $nextNumber = 1;
        DB::table('patients')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $patientId) use (&$nextNumber): void {
                DB::table('patients')->where('id', $patientId)->update([
                    'patient_number' => $nextNumber++,
                ]);
            });

        Schema::create('patient_number_counters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_number');
        });
        DB::table('patient_number_counters')->insert([
            'id' => 1,
            'next_number' => $nextNumber,
        ]);

        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_number')->nullable(false)->change();
            $table->unique('patient_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_number_counters');

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['patient_number']);
            $table->dropColumn('patient_number');
        });
    }
};
