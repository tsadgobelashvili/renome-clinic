<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->foreignId('purchase_id')->nullable()->index()->constrained('purchases')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_id'));
    }
};
