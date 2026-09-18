<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'purchase_items_purchase_lookup_idx';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $named = DB::selectOne('SELECT indisvalid, indisready FROM pg_index WHERE indexrelid = to_regclass(?)', [self::INDEX]);
            if ($named && (! $named->indisvalid || ! $named->indisready)) {
                throw new RuntimeException('Index '.self::INDEX.' has an incomplete concurrent build; inspect and repair it before retrying.');
            }

            // A full, valid composite B-tree beginning with purchase_id is sufficient too.
            // Partial, expression-leading and INCLUDE-only coverage is not equivalent.
            $equivalent = DB::selectOne("SELECT 1 FROM pg_index i
                JOIN pg_class c ON c.oid = i.indexrelid
                JOIN pg_am am ON am.oid = c.relam
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                WHERE i.indrelid = to_regclass('purchase_items') AND a.attname = 'purchase_id'
                  AND am.amname = 'btree' AND i.indisvalid AND i.indisready
                  AND i.indpred IS NULL LIMIT 1");
            if ($equivalent) {
                return;
            }
            if ($named) {
                throw new RuntimeException('Index name '.self::INDEX.' is already used by a different definition.');
            }

            DB::statement('CREATE INDEX CONCURRENTLY '.self::INDEX.' ON purchase_items (purchase_id)');

            return;
        }

        foreach (Schema::getIndexes('purchase_items') as $index) {
            if (($index['columns'][0] ?? null) === 'purchase_id') {
                return;
            }
        }
        Schema::table('purchase_items', fn (Blueprint $table) => $table->index('purchase_id', self::INDEX));
    }

    public function down(): void
    {
        // Never remove an equivalent pre-existing index with another name.
        if (! Schema::hasIndex('purchase_items', self::INDEX)) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY '.self::INDEX);
        } else {
            Schema::table('purchase_items', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
