<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->string('patient_group_slug')->nullable()->after('doctor_id')->index();
        });
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->string('patient_group_slug')->nullable()->after('visit_treatment_case_id')->index();
        });

        DB::table('salary_settlement_items as items')->update([
            'patient_group_slug' => DB::raw("COALESCE((SELECT patient_groups.slug FROM visits JOIN patients ON patients.id = visits.patient_id JOIN patient_groups ON patient_groups.id = patients.patient_group_id WHERE visits.id = items.visit_id), 'clinic')"),
        ]);

        DB::table('salary_settlements')->orderBy('id')->each(function (object $settlement): void {
            $groups = DB::table('salary_settlement_items')
                ->where('salary_settlement_id', $settlement->id)
                ->whereNotNull('patient_group_slug')
                ->distinct()
                ->pluck('patient_group_slug');

            DB::table('salary_settlements')->where('id', $settlement->id)->update([
                'patient_group_slug' => $groups->count() === 1 ? $groups->first() : ($groups->isEmpty() ? null : 'mixed'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('salary_settlement_items', fn (Blueprint $table) => $table->dropColumn('patient_group_slug'));
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->dropColumn('patient_group_slug'));
    }
};
