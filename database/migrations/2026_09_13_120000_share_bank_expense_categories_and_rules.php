<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bank_transactions', 'bank_categorization_rules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
                $table->foreignId('expense_subcategory_id')->nullable()->constrained('expense_subcategories')->restrictOnDelete();
            });
        }
        Schema::table('bank_categorization_rules', function (Blueprint $table) {
            $table->string('counterparty')->nullable();
            $table->string('purpose_keyword')->nullable();
            $table->string('counterparty_account')->nullable();
        });
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->foreignId('categorization_rule_id')->nullable()->constrained('bank_categorization_rules')->nullOnDelete());

        // Adopt existing IDs and assignments; names and financial facts are not rewritten.
        foreach (DB::table('bank_categories')->where('accounting_treatment', 'expense')->get() as $category) {
            $shared = $category->expense_category_id ?? DB::table('expense_categories')->where('name', $category->name)->value('id');
            if (! $shared) {
                $shared = DB::table('expense_categories')->insertGetId(['name' => $category->name, 'active' => $category->active, 'sort_order' => $category->sort_order, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('bank_categories')->where('id', $category->id)->update(['expense_category_id' => $shared]);
            DB::table('bank_transactions')->where('bank_category_id', $category->id)->update(['expense_category_id' => $shared]);
            DB::table('bank_categorization_rules')->where('bank_category_id', $category->id)->update(['expense_category_id' => $shared]);
        }
        foreach (DB::table('bank_categorization_rules')->get() as $rule) {
            DB::table('bank_categorization_rules')->where('id', $rule->id)->update($rule->field === 'counterparty'
                ? ['counterparty' => $rule->phrase] : ['purpose_keyword' => $rule->phrase]);
        }
    }

    public function down(): void
    {
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('categorization_rule_id'));
        foreach (['bank_transactions', 'bank_categorization_rules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('expense_subcategory_id');
                $table->dropConstrainedForeignId('expense_category_id');
            });
        }
        Schema::table('bank_categorization_rules', fn (Blueprint $table) => $table->dropColumn(['counterparty', 'purpose_keyword', 'counterparty_account']));
    }
};
