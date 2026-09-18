<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            $table->string('first_name_en', 100)->nullable();
            $table->string('last_name_en', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn(['first_name_en', 'last_name_en']));
    }
};
