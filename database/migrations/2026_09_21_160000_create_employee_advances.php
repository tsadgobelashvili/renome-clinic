<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $view = $this->detachSqliteView();
        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('posting_key')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('date')->index();
            $table->string('source', 30);
            $table->string('currency', 3)->default('GEL');
            $table->foreignId('bank_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('employee_advance_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('posting_key')->unique();
            $table->foreignId('employee_advance_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->index(['employee_advance_id', 'kind']);
            $table->date('expense_date')->index();
            $table->decimal('amount', 14, 2);
            $table->foreignId('purchase_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('expense_direction_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('expense_type_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->string('description', 500);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        foreach (['cashbox_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('employee_advance_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('employee_advance_key')->nullable()->unique();
            });
        }
        if ($view) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->detachSqliteView();
        foreach (['cashbox_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('employee_advance_id');
                $table->dropUnique(['employee_advance_key']);
                $table->dropColumn('employee_advance_key');
            });
        }
        Schema::dropIfExists('employee_advance_entries');
        Schema::dropIfExists('employee_advances');
        if ($view) {
            DB::statement($view);
        }
    }

    private function detachSqliteView(): ?string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return null;
        }
        $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql');
        if ($sql) {
            DB::statement('DROP VIEW partner_finance_entries');
        }

        return $sql;
    }
};
