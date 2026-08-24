<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('treatment_case_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_case_id');
        });
    }
};
