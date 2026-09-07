<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_main_works', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_case_id')->constrained()->cascadeOnDelete();
            $table->string('material', 20);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('shade')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO lab_main_works (lab_case_id, material, quantity, shade, sort_order, created_at, updated_at)
            SELECT id, material, COALESCE(quantity, 1), shade, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM lab_cases
            WHERE material IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_main_works');
    }
};
