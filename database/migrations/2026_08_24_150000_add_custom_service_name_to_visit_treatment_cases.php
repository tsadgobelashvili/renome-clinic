<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->foreignId('treatment_case_id')->nullable()->change();
            $table->string('custom_service_name')->nullable()->after('treatment_case_id');
            $table->index('custom_service_name');
        });
    }

    public function down(): void
    {
        if (DB::table('visit_treatment_cases')->whereNull('treatment_case_id')->exists()) {
            throw new RuntimeException('Cannot roll back while manual Visit manipulations exist.');
        }

        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->dropIndex(['custom_service_name']);
            $table->dropColumn('custom_service_name');
            $table->foreignId('treatment_case_id')->nullable(false)->change();
        });
    }
};
