<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('case_type');
            $table->string('status')->default('in_progress');
            $table->string('teeth')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_cases');
    }
};
