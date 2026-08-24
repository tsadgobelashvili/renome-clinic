<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->decimal('unit_price', 12, 2)->default(0)->after('quantity');
        });

        Schema::create('direct_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_treatment_case_id')
                ->constrained('visit_treatment_cases')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['visit_treatment_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_expenses');

        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->dropColumn('unit_price');
        });
    }
};
