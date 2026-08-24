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
            $table->string('consultation_source', 30)->nullable()->after('visit_type');
            $table->decimal('consultation_fee', 12, 2)->default(0)->after('consultation_source');
        });

        DB::table('visits')
            ->where('visit_type', 'consultation')
            ->whereNull('consultation_source')
            ->update(['consultation_source' => 'our_patient']);

        foreach ([
            ['name' => '3D CT', 'default_price' => 60],
            ['name' => 'პანორამა', 'default_price' => 40],
        ] as $service) {
            $existingId = DB::table('treatment_cases')
                ->whereRaw('LOWER(name) = LOWER(?)', [$service['name']])
                ->value('id');

            if ($existingId) {
                DB::table('treatment_cases')->where('id', $existingId)->update([
                    'category' => 'tomography',
                    'default_price' => $service['default_price'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('treatment_cases')->insert([
                ...$service,
                'category' => 'tomography',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $serviceIdsInUse = DB::table('visit_treatment_cases')->pluck('treatment_case_id');

        DB::table('treatment_cases')
            ->where('category', 'tomography')
            ->whereIn('name', ['3D CT', 'პანორამა'])
            ->when($serviceIdsInUse->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $serviceIdsInUse))
            ->delete();

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn(['consultation_source', 'consultation_fee']);
        });
    }
};
