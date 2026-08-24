<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_estimate_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('treatment_estimate_option_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->index(['treatment_estimate_option_id', 'sort_order']);
        });

        Schema::table('treatment_estimate_items', function (Blueprint $table): void {
            $table->foreignId('treatment_estimate_stage_id')
                ->nullable()
                ->constrained('treatment_estimate_stages')
                ->cascadeOnDelete();
        });

        DB::table('treatment_estimate_options')->orderBy('id')->each(function (object $option): void {
            $stageId = DB::table('treatment_estimate_stages')->insertGetId([
                'treatment_estimate_option_id' => $option->id,
                'name' => 'ძირითადი ეტაპი',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('treatment_estimate_items')
                ->where('treatment_estimate_option_id', $option->id)
                ->update(['treatment_estimate_stage_id' => $stageId]);
        });

        if (DB::table('treatment_estimate_items')->whereNull('treatment_estimate_stage_id')->exists()) {
            throw new RuntimeException('Some treatment estimate items could not be assigned to a stage.');
        }

        Schema::table('treatment_estimate_items', function (Blueprint $table): void {
            $table->foreignId('treatment_estimate_stage_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_estimate_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('treatment_estimate_stage_id');
        });

        Schema::dropIfExists('treatment_estimate_stages');
    }
};
