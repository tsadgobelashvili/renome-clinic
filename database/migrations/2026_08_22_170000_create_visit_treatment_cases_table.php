<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_treatment_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('treatment_case_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('teeth')->nullable();
            $table->text('comment')->nullable();
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(['visit_id', 'fingerprint']);
        });

        DB::table('visits')
            ->whereNotNull('treatment_case_id')
            ->orderBy('id')
            ->each(function ($visit): void {
                DB::table('visit_treatment_cases')->insert([
                    'visit_id' => $visit->id,
                    'treatment_case_id' => $visit->treatment_case_id,
                    'quantity' => 1,
                    'teeth' => null,
                    'comment' => null,
                    'fingerprint' => hash('sha256', json_encode([
                        (int) $visit->treatment_case_id,
                        1,
                        null,
                        null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_case_id');
        });
    }

    public function down(): void
    {
        $hasVisitsWithMultipleCases = DB::table('visit_treatment_cases')
            ->select('visit_id')
            ->groupBy('visit_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasVisitsWithMultipleCases) {
            throw new RuntimeException('Cannot roll back without losing Visits that have multiple Treatment Cases.');
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('treatment_case_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::table('visit_treatment_cases')
            ->orderBy('id')
            ->each(function ($item): void {
                DB::table('visits')
                    ->where('id', $item->visit_id)
                    ->update(['treatment_case_id' => $item->treatment_case_id]);
            });

        Schema::dropIfExists('visit_treatment_cases');
    }
};
