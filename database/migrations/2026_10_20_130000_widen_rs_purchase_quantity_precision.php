<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same nine integer digits, six fractional digits. No value backfill/recalculation.
        Schema::table('purchase_items', fn (Blueprint $table) => $table->decimal('quantity', 15, 6)->change());
    }

    public function down(): void
    {
        // Deliberately retain scale: shrinking it could round newer legitimate quantities.
    }
};
