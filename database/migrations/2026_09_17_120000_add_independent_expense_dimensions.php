<?php

use App\Services\ExpenseDimensions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('classification_dimension', 16)->nullable();
            $table->string('classification_code', 40)->nullable();
            $table->unique(['classification_dimension', 'classification_code'], 'expense_dimension_code_unique');
        });
        foreach (['direction' => ExpenseDimensions::DIRECTIONS, 'type' => ExpenseDimensions::TYPES] as $dimension => $labels) {
            foreach ($labels as $code => $name) {
                $candidate = DB::table('expense_categories')->whereNull('classification_dimension')->orderBy('id')->get()
                    ->first(fn ($row) => ($dimension === 'direction' ? ExpenseDimensions::directionCode($row->name)
                        : ExpenseDimensions::typeCode($row->reporting_code ?? $row->name)) === $code);
                $data = ['classification_dimension' => $dimension, 'classification_code' => $code];
                if ($candidate) {
                    // Keep existing names, IDs and reporting codes intact; display canonical labels separately.
                    DB::table('expense_categories')->where('id', $candidate->id)->update($data);
                } else {
                    DB::table('expense_categories')->insert($data + ['name' => $name, 'active' => true, 'sort_order' => 40,
                        'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        // SQLite rebuilds tables when adding constrained columns; preserve the existing view definition.
        $view = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql') : null;
        if ($view) {
            DB::statement('DROP VIEW partner_finance_entries');
        }
        foreach (['finance_transactions', 'partner_finance_transactions', 'direct_expenses', 'bank_transactions', 'bank_categorization_rules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('expense_direction_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
                $table->foreignId('expense_type_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            });
        }
        if ($view) {
            DB::statement($view);
        }
        (new ExpenseDimensions)->backfill();
    }

    public function down(): void
    {
        // Classification is historical data; never silently destroy it through rollback.
        throw new RuntimeException('Expense dimension rollback requires an explicit data-preservation plan.');
    }
};
