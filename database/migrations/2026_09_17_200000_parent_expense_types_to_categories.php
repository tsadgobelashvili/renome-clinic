<?php

use App\Services\ExpenseDimensions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('legacy_type_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->dropUnique('expense_dimension_code_unique');
            $table->unique(['parent_id', 'classification_code'], 'expense_parent_code_unique');
            $table->unique(['parent_id', 'legacy_type_id'], 'expense_parent_legacy_type_unique');
        });
        app(ExpenseDimensions::class)->reset();
        app(ExpenseDimensions::class)->backfillHierarchy();
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve historical parent/child classifications before rolling back.');
    }
};
