<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->foreignId('treatment_estimate_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('treatment_estimate_option_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('treatment_estimate_option_id');
            $table->dropConstrainedForeignId('treatment_estimate_id');
        });
    }
};
