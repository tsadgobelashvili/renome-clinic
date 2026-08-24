<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_estimate_options', function (Blueprint $table) {
            $table->string('discount_type', 20)->default('amount');
            $table->decimal('discount_value', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('treatment_estimate_options', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
