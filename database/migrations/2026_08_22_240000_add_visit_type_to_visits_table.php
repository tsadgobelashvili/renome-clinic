<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('visit_type', 20)->default('treatment');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('visit_type');
        });
    }
};
