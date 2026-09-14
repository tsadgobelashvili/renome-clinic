<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_import_batches', function (Blueprint $table) {
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rolled_back_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bank_import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rolled_back_by');
            $table->dropColumn(['rolled_back_at', 'rolled_back_rows']);
        });
    }
};
