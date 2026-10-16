<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->preservingSqliteView(fn () => $this->addColumns());
    }

    private function addColumns(): void
    {
        // Source identities point into the same catalog; these rows carry no classification or money.
        Schema::create('purchase_product_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('identity_key', 64)->unique();
            $table->foreignId('purchase_product_id')->constrained('purchase_products')->restrictOnDelete();
        });
        // Manual catalog entries need not have an RS supplier. No existing rows are reclassified.
        Schema::table('purchase_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
        });
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('purchase_product_id')->nullable()->constrained('purchase_products')->restrictOnDelete();
                $table->foreignId('purchase_product_group_id')->nullable()->constrained('purchase_product_groups')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $this->preservingSqliteView(fn () => $this->removeColumns());
    }

    private function removeColumns(): void
    {
        Schema::dropIfExists('purchase_product_aliases');
        foreach (['finance_transactions', 'partner_finance_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('purchase_product_group_id');
                $table->dropConstrainedForeignId('purchase_product_id');
            });
        }
        // Keep nullable suppliers: restoring NOT NULL could invalidate manual catalog entries.
    }

    private function preservingSqliteView(callable $change): void
    {
        $sql = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql') : null;
        if ($sql) {
            DB::statement('DROP VIEW partner_finance_entries');
        }
        try {
            $change();
        } finally {
            if ($sql) {
                DB::statement($sql);
            }
        }
    }
};
