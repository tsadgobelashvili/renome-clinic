<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->decimal('default_price', 12, 2)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->dropColumn('default_price');
        });
    }
};
