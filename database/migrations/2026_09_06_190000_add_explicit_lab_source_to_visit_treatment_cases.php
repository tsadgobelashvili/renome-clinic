<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->foreignId('lab_main_work_id')->nullable()->unique()->after('visit_id')
                ->constrained('lab_main_works')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lab_main_work_id');
        });
    }
};
