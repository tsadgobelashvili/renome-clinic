<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // PostgreSQL concurrent index creation must not run inside a transaction.
    public $withinTransaction = false;

    private const INDEXES = [
        'visits' => ['name' => 'visits_patient_date_lookup_idx', 'columns' => ['patient_id', 'visit_date']],
        'payments' => ['name' => 'payments_visit_currency_lookup_idx', 'columns' => ['visit_id', 'currency']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (Schema::hasIndex($table, $index['name'])) {
                if (DB::getDriverName() === 'pgsql') {
                    $valid = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index['name']]);
                    if ($valid && ! $valid->indisvalid) {
                        throw new RuntimeException("Index {$index['name']} is invalid after an interrupted concurrent build; repair it before retrying.");
                    }
                }

                continue;
            }
            if (Schema::hasIndex($table, $index['columns'])) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                $columns = implode(', ', $index['columns']);
                DB::statement("CREATE INDEX CONCURRENTLY {$index['name']} ON {$table} ({$columns})");
            } else {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($index['columns'], $index['name']));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! Schema::hasIndex($table, $index['name'])) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX CONCURRENTLY {$index['name']}");
            } else {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
            }
        }
    }
};
