<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->decimal('normal_salary_total', 12, 2)->default(0)->after('percentage');
            $table->decimal('owner_split_received_total', 12, 2)->default(0)->after('normal_salary_total');
        });

        DB::table('salary_settlements')->update([
            'normal_salary_total' => DB::raw('salary_total'),
        ]);

        DB::table('owner_salary_shares')
            ->whereNotNull('recipient_salary_settlement_id')
            ->where('status', 'settled')
            ->selectRaw('recipient_salary_settlement_id, SUM(amount) AS owner_total')
            ->groupBy('recipient_salary_settlement_id')
            ->orderBy('recipient_salary_settlement_id')
            ->get()
            ->each(function (object $row): void {
                $settlement = DB::table('salary_settlements')->find($row->recipient_salary_settlement_id);
                if (! $settlement) {
                    return;
                }

                $ownerTotal = round((float) $row->owner_total, 2);
                DB::table('salary_settlements')->where('id', $settlement->id)->update([
                    'owner_split_received_total' => $ownerTotal,
                    'normal_salary_total' => max(round((float) $settlement->salary_total - $ownerTotal, 2), 0),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->dropColumn(['normal_salary_total', 'owner_split_received_total']);
        });
    }
};
