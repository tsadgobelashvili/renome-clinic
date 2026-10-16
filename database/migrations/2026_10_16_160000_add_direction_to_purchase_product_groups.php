<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_product_groups', function (Blueprint $table): void {
            $table->foreignId('expense_direction_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
        });
        // No backfill: legacy groups retain their existing inferred/ambiguous classifications.
    }

    public function down(): void
    {
        Schema::table('purchase_product_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_direction_id');
        });
    }
};
