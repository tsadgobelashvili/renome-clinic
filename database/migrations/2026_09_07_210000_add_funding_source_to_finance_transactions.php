<?php

use App\Models\FinanceTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->string('funding_source', 20)->nullable()->after('cash_source')->index();
        });

        DB::table('finance_transactions')->whereNotNull('clinic_cash_gel')->orderBy('id')
            ->each(function (object $transaction): void {
                DB::table('finance_transactions')->where('id', $transaction->id)->update([
                    'funding_source' => FinanceTransaction::classifyFundingSource(
                        $transaction->clinic_cash_gel,
                        $transaction->israeli_cash_gel,
                    ),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->dropIndex(['funding_source']);
            $table->dropColumn('funding_source');
        });
    }
};
