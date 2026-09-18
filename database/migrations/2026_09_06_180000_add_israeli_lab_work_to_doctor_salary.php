<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            $table->decimal('israeli_lab_zircon_rate', 10, 2)->nullable()->after('compensation_percentage');
        });

        foreach (require database_path('legacy_doctor_salary_defaults.php') as $defaults) {
            if (! isset($defaults['israeli_lab_zircon_rate'])) {
                continue;
            }

            DB::table('doctors')
                ->whereIn(DB::raw('LOWER(first_name)'), $defaults['first_names'])
                ->whereIn(DB::raw('LOWER(last_name)'), $defaults['last_names'])
                ->update(['israeli_lab_zircon_rate' => $defaults['israeli_lab_zircon_rate']]);
        }

        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->foreignId('visit_id')->nullable()->change();
            $table->foreignId('visit_treatment_case_id')->nullable()->change();
            $table->foreignId('lab_main_work_id')->nullable()->unique()->after('visit_treatment_case_id')
                ->constrained('lab_main_works')->restrictOnDelete();
            $table->unsignedInteger('quantity_snapshot')->nullable()->after('lab_main_work_id');
            $table->decimal('unit_rate_snapshot', 10, 2)->nullable()->after('quantity_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lab_main_work_id');
            $table->dropColumn(['quantity_snapshot', 'unit_rate_snapshot']);
        });

        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn('israeli_lab_zircon_rate');
        });
    }
};
