<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->index(['lab_case_id', 'material'], 'lab_main_works_case_material_index');
        });
    }

    public function down(): void
    {
        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->dropIndex('lab_main_works_case_material_index');
        });
    }
};
