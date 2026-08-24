<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_notes')->nullable();
            $table->text('doctor_notes')->nullable();
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['status', 'visit_time']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('status')->default('scheduled');
            $table->time('visit_time')->nullable();
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn([
                'complaint',
                'diagnosis',
                'treatment_notes',
                'doctor_notes',
            ]);
        });
    }
};
