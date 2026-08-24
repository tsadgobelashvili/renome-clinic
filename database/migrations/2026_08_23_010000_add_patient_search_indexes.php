<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('patients')->orderBy('id')->each(function (object $patient): void {
            $personalId = trim((string) ($patient->personal_id ?? ''));
            DB::table('patients')->where('id', $patient->id)->update([
                'personal_id' => $personalId === '' ? null : $personalId,
            ]);
        });

        $duplicates = DB::table('patients')
            ->whereNotNull('personal_id')
            ->select('personal_id')
            ->groupBy('personal_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('personal_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate patient personal IDs must be resolved: '.$duplicates->join(', '));
        }

        Schema::table('patients', function (Blueprint $table): void {
            $table->index('first_name');
            $table->index('last_name');
            $table->index('phone');
            $table->unique('personal_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['first_name']);
            $table->dropIndex(['last_name']);
            $table->dropIndex(['phone']);
            $table->dropUnique(['personal_id']);
        });
    }
};
