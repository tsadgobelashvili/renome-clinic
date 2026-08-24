<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('estimate_date');
            $table->string('estimated_duration')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('treatment_estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_estimate_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_estimate_items');
        Schema::dropIfExists('treatment_estimates');
    }
};
