<?php

use App\Models\TreatmentCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->string('statistics_group', 32)->nullable()->after('category')->index();
        });

        DB::table('treatment_cases')->orderBy('id')->select(['id', 'name'])->each(function (object $treatment): void {
            DB::table('treatment_cases')->where('id', $treatment->id)->update([
                'statistics_group' => TreatmentCase::inferStatisticsGroup((string) $treatment->name),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->dropColumn('statistics_group');
        });
    }
};
