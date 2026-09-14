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
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->boolean('uses_allocations')->default(false));
        Schema::create('salary_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_settlement_id')->index()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->decimal('total_gel', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('salary_payout_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_payout_id')->index()->constrained()->restrictOnDelete();
            $table->string('source');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('exchange_rate', 14, 6)->nullable();
            $table->decimal('gel_equivalent', 14, 2);
            $table->timestamps();
        });
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('salary_payout_allocation_id')->nullable()->unique()->constrained()->restrictOnDelete());
        }
        if ($view) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->detachSqliteView();
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['salary_payout_allocation_id']);
                $table->dropConstrainedForeignId('salary_payout_allocation_id');
            });
        }
        Schema::dropIfExists('salary_payout_allocations');
        Schema::dropIfExists('salary_payouts');
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->dropColumn('uses_allocations'));
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
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');

        return $sql;
    }
};
