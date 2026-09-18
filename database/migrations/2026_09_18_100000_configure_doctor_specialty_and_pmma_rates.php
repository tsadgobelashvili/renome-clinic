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
        Schema::table('doctors', function (Blueprint $table): void {
            $table->json('specialties')->nullable();
            $table->decimal('israeli_lab_pmma_rate', 10, 2)->nullable();
        });
        DB::table('doctors')->orderBy('id')->chunkById(100, function ($doctors): void {
            foreach ($doctors as $doctor) {
                $rates = json_decode($doctor->compensation_category_percentages ?? '[]', true) ?? [];
                $selected = array_intersect(array_keys($rates), array_keys(TreatmentCase::CATEGORIES));
                $legacy = mb_strtolower($doctor->specialty ?? '');
                foreach (['surgery' => ['ქირურგ', 'surg'], 'therapy' => ['თერაპ', 'therap'],
                    'orthopedics' => ['ორთოპედ', 'orthoped', 'orthopaed'], 'periodontology' => ['პაროდონტ', 'periodont'],
                    'orthodontics' => ['ორთოდონტ', 'orthodont'], 'pediatric_dentistry' => ['ბავშვ', 'pediatric'],
                    'consultation' => ['კონსულტ', 'consultation'], 'tomography' => ['ტომოგრაფ', 'tomograph']] as $key => $aliases) {
                    foreach ($aliases as $alias) {
                        if (str_contains($legacy, $alias)) {
                            $selected[] = $key;
                            break;
                        }
                    }
                }
                if ((float) $doctor->israeli_lab_zircon_rate > 0) {
                    $selected[] = 'orthopedics';
                }
                $selected = array_values(array_unique($selected));
                foreach ($selected as $key) {
                    if (! array_key_exists($key, $rates)) {
                        $rates[$key] = (float) ($doctor->compensation_percentage ?? 0);
                    }
                }
                DB::table('doctors')->where('id', $doctor->id)->update([
                    'specialties' => json_encode($selected, JSON_THROW_ON_ERROR),
                    'compensation_category_percentages' => $rates === [] ? $doctor->compensation_category_percentages : json_encode($rates, JSON_THROW_ON_ERROR),
                    // Preserve the previous constant for existing doctors only; new profiles configure it explicitly.
                    'israeli_lab_pmma_rate' => 25,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve configured doctor specialties and PMMA rates before rolling back.');
    }
};
