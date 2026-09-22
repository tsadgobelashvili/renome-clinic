<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_catalog_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_name')->unique();
            $table->foreignId('treatment_case_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_catalog_mappings');
    }
};
