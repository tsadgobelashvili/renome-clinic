<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::table('purchase_products', function (Blueprint $table): void {
            $table->foreignId('purchase_product_group_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_products', function (Blueprint $table): void {
            $table->dropForeign(['purchase_product_group_id']);
            $table->dropIndex(['purchase_product_group_id']);
            $table->dropColumn('purchase_product_group_id');
        });
        Schema::dropIfExists('purchase_product_groups');
    }
};
