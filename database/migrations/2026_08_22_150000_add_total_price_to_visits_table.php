<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->decimal('total_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('total_price');
        });
    }
};
