<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_estimate_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_estimate_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('estimated_duration')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('treatment_estimate_items', function (Blueprint $table) {
            $table->foreignId('treatment_estimate_option_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('treatment_estimates')->orderBy('id')->each(function ($estimate): void {
            $optionId = DB::table('treatment_estimate_options')->insertGetId([
                'treatment_estimate_id' => $estimate->id,
                'name' => 'ვარიანტი 1',
                'estimated_duration' => $estimate->estimated_duration,
                'comment' => $estimate->comment,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('treatment_estimate_items')
                ->where('treatment_estimate_id', $estimate->id)
                ->update(['treatment_estimate_option_id' => $optionId]);
        });

        Schema::table('treatment_estimate_items', function (Blueprint $table) {
            $table->foreignId('treatment_estimate_id')->nullable()->change();
            $table->foreignId('treatment_estimate_option_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        DB::table('treatment_estimate_items')
            ->join('treatment_estimate_options', 'treatment_estimate_items.treatment_estimate_option_id', '=', 'treatment_estimate_options.id')
            ->update(['treatment_estimate_id' => DB::raw('treatment_estimate_options.treatment_estimate_id')]);

        Schema::table('treatment_estimate_items', function (Blueprint $table) {
            $table->foreignId('treatment_estimate_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('treatment_estimate_option_id');
        });

        Schema::dropIfExists('treatment_estimate_options');
    }
};
