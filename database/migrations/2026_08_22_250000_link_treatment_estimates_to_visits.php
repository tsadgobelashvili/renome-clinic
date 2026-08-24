<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_estimates', function (Blueprint $table): void {
            $table->foreignId('visit_id')
                ->nullable()
                ->after('doctor_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visit_id');
        });
    }
};
