<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('discount_type', 20)->default('amount');
            $table->decimal('discount_value', 12, 2)->default(0);
        });

        DB::table('visits')->update([
            'discount_type' => 'amount',
            'discount_value' => DB::raw('discount_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
