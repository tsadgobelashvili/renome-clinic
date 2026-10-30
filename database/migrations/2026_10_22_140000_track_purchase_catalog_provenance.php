<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_products', function (Blueprint $table): void {
            // Unknown historical origins remain unknown; no name/code/date backfill.
            $table->string('catalog_origin', 16)->nullable();
            $table->boolean('manually_retained')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_products', fn (Blueprint $table) => $table->dropColumn(['catalog_origin', 'manually_retained']));
    }
};
