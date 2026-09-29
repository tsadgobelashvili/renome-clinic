<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', fn (Blueprint $table) => $table->boolean('external_lab_enabled')->default(false));
    }

    public function down(): void
    {
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('external_lab_enabled'));
    }
};
