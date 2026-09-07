<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->foreignId('modeled_by')->nullable()->after('modeling')->constrained('users')->nullOnDelete();
            $table->foreignId('milled_by')->nullable()->after('milling_technician')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('milled_by');
            $table->dropConstrainedForeignId('modeled_by');
        });
    }
};
