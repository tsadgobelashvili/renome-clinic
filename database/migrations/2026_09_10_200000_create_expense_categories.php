<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('expense_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        foreach (['ქირურგია', 'თერაპია', 'ორთოპედია', 'ლაბორატორია', 'ადმინისტრაციული', 'სხვა'] as $order => $name) {
            DB::table('expense_categories')->insert(['name' => $name, 'active' => true, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
        $view = $this->detachSqliteView();
        foreach (['finance_transactions', 'partner_finance_transactions', 'direct_expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('expense_subcategory_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        if ($view) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->detachSqliteView();
        foreach (['finance_transactions', 'partner_finance_transactions', 'direct_expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('expense_subcategory_id');
                $table->dropConstrainedForeignId('expense_category_id');
            });
        }
        Schema::dropIfExists('expense_subcategories');
        Schema::dropIfExists('expense_categories');
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
