<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_categories', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
            $table->string('accounting_treatment', 12)->default('exclude');
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->boolean('exclude_from_pnl')->default(false);
            $table->boolean('include_embedded_fee')->default(false);
            $table->string('classification_source', 12)->nullable();
        });
        Schema::create('bank_categorization_rules', function (Blueprint $table) {
            $table->id();
            $table->string('field', 20);
            $table->string('phrase');
            $table->foreignId('bank_category_id')->constrained('bank_categories')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $defaults = [
            'card_settlement' => ['ბარათის ჩარიცხვა', 'settlement'],
            'cash_deposit' => ['ნაღდის შეტანა', 'transfer'],
            'internal_transfer' => ['შიდა გადარიცხვა', 'transfer'],
            'currency_exchange' => ['ვალუტის კონვერტაცია', 'transfer'],
            'bank_fee' => ['ბანკის საკომისიო', 'expense'],
            'supplier' => ['მომწოდებელი / მასალები', 'expense'],
            'rent' => ['ქირა', 'expense'], 'utilities' => ['კომუნალური', 'expense'],
            'salary' => ['ხელფასი', 'expense'], 'payroll_tax' => ['ხელფასის გადასახადები', 'expense'],
            'taxes' => ['გადასახადები', 'expense'], 'equipment' => ['მოწყობილობა', 'expense'],
            'operating_expense' => ['სხვა საოპერაციო ხარჯი', 'expense'],
            'business_income' => ['სხვა ბიზნეს შემოსავალი', 'income'],
            'uncategorized' => ['დაუკატეგორიზებელი', 'exclude'],
        ];
        foreach ($defaults as $code => [$name, $treatment]) {
            $existing = DB::table('bank_categories')->where('name', $name)->whereNull('code')->orderBy('id')->first();
            if ($existing) {
                DB::table('bank_categories')->where('id', $existing->id)->update(['code' => $code, 'accounting_treatment' => $treatment]);
            } else {
                DB::table('bank_categories')->insert(['code' => $code, 'name' => $name, 'accounting_treatment' => $treatment, 'sort_order' => 20, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // Existing assignments are user choices and must survive automatic classification.
        DB::table('bank_transactions')->whereNotNull('bank_category_id')->update(['classification_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_categorization_rules');
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->dropColumn(['exclude_from_pnl', 'include_embedded_fee', 'classification_source']));
        Schema::table('bank_categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'accounting_treatment']);
        });
    }
};
