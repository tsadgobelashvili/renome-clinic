<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('primary_patient_id')->constrained('patients')->restrictOnDelete();
            $table->unsignedBigInteger('duplicate_patient_id')->index();
            $table->json('duplicate_patient_snapshot');
            $table->json('moved_records');
            $table->json('copied_fields')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at');
            $table->timestamps();

            $table->index(['primary_patient_id', 'merged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
    }
};
