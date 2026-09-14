<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_categorization_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_category_id')->nullable()->change();
            $table->string('field', 20)->nullable()->change();
            $table->string('phrase')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve valid shared-only rules; forcing the old non-null fields would lose data.
    }
};
