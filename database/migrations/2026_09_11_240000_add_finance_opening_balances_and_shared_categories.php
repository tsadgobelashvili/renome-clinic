<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);
            $table->string('bank', 20)->default('');
            $table->string('account_identifier');
            $table->string('currency', 3);
            $table->date('effective_date');
            $table->decimal('amount', 18, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'bank', 'account_identifier', 'currency', 'effective_date'], 'finance_opening_account_date_unique');
        });
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->boolean('is_legacy')->default(false));
        Schema::table('expense_categories', fn (Blueprint $table) => $table->string('reporting_code', 40)->nullable()->unique());
        Schema::table('bank_categories', fn (Blueprint $table) => $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete());

        $groups = [
            'materials' => ['მასალები / მომწოდებელი', 'supplier'], 'rent' => ['ქირა', 'rent'],
            'utilities' => ['კომუნალური', 'utilities'], 'salary' => ['ხელფასი', 'salary'],
            'payroll_tax' => ['სახელფასო გადასახადი / პენსია', 'payroll_tax'], 'taxes' => ['გადასახადები', 'taxes'],
            'equipment' => ['მოწყობილობა', 'equipment'], 'bank_fee' => ['ბანკის საკომისიო', 'bank_fee'],
            'operating_expense' => ['სხვა საოპერაციო ხარჯი', 'operating_expense'],
        ];
        foreach ($groups as $code => [$name, $bankCode]) {
            $id = DB::table('expense_categories')->where('name', $name)->orderBy('id')->value('id');
            if ($id) {
                DB::table('expense_categories')->where('id', $id)->update(['reporting_code' => $code]);
            } else {
                $id = DB::table('expense_categories')->insertGetId(['name' => $name, 'reporting_code' => $code, 'active' => true, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('bank_categories')->where('code', $bankCode)->update(['expense_category_id' => $id]);
        }
        foreach (['owner_withdrawal' => 'მფლობელის გატანა', 'excluded' => 'გამორიცხული'] as $code => $name) {
            if (! DB::table('bank_categories')->where('code', $code)->exists()) {
                DB::table('bank_categories')->insert(['code' => $code, 'name' => $name, 'accounting_treatment' => 'exclude', 'active' => true, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('bank_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('expense_category_id'));
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['reporting_code']);
            $table->dropColumn('reporting_code');
        });
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->dropColumn('is_legacy'));
        Schema::dropIfExists('finance_opening_balances');
    }
};
