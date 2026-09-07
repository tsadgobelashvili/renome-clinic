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
            $table->json('compensation_category_percentages')->nullable()->after('compensation_percentage');
        });
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->decimal('salary_percentage_snapshot', 5, 2)->nullable()->after('salary_base');
        });

        foreach (config('doctor_salary_defaults') as $defaults) {
            DB::table('doctors')
                ->whereIn(DB::raw('LOWER(first_name)'), $defaults['first_names'])
                ->whereIn(DB::raw('LOWER(last_name)'), $defaults['last_names'])
                ->update([
                    'compensation_percentage' => $defaults['percentage'],
                    'compensation_category_percentages' => isset($defaults['category_percentages'])
                        ? json_encode($defaults['category_percentages'])
                        : null,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->dropColumn('salary_percentage_snapshot');
        });
        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn('compensation_category_percentages');
        });
    }
};
