<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('material_quantity', 20, 6)->nullable();
                $table->string('material_unit')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['material_quantity', 'material_unit']));
        }
    }
};
