<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('israeli_visit_salary_type')->nullable();
            $table->decimal('israeli_visit_salary_rate', 10, 2)->nullable();
            $table->string('israeli_salary_payment_method')->default('cash');
        });
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->string('israeli_payment_method')->nullable());
    }

    public function down(): void
    {
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->dropColumn('israeli_payment_method'));
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn(['israeli_visit_salary_type', 'israeli_visit_salary_rate', 'israeli_salary_payment_method']));
    }
};
