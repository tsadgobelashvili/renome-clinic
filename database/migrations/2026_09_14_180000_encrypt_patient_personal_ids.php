<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            // Uniqueness already exists for personal_id; move it to the deterministic index.
            $table->dropUnique(['personal_id']);
            $table->text('personal_id')->nullable()->change();
            $table->char('personal_id_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (DB::table('patients')->whereNotNull('personal_id_hash')
            ->orWhere('personal_id', 'like', 'encrypted:v1:%')->exists()) {
            throw new RuntimeException('Cannot remove patient identifier encryption schema while encrypted data exists.');
        }

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['personal_id_hash']);
            $table->dropColumn('personal_id_hash');
            $table->string('personal_id')->nullable()->change();
            $table->unique('personal_id');
        });
    }
};
