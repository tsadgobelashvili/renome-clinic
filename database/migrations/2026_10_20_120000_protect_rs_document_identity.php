<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Refuse conflicts; never merge, delete or re-key historical documents.
        if (DB::table('purchases')->select('source', 'supplier_id', 'source_document_id')->whereNotNull('source_document_id')
            ->groupBy('source', 'supplier_id', 'source_document_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate purchase source identities require review before adding the unique constraint. No records were changed.');
        }
        Schema::table('purchases', fn (Blueprint $table) => $table->unique(['source', 'supplier_id', 'source_document_id'], 'purchases_source_identity_unique'));
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropUnique('purchases_source_identity_unique'));
    }
};
