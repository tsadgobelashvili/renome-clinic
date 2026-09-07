<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('first_name_latin')->nullable()->after('last_name');
            $table->string('last_name_latin')->nullable()->after('first_name_latin');
            $table->index(['first_name_latin', 'last_name_latin'], 'patients_latin_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex('patients_latin_name_index');
            $table->dropColumn(['first_name_latin', 'last_name_latin']);
        });
    }
};
