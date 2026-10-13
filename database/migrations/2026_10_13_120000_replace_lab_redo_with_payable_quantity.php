<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->unsignedInteger('payable_quantity')->nullable();
        });

        // Preserve entered adjustments without changing salary/accounting snapshots.
        // The old zero default becomes NULL; a full reduction becomes explicit payable zero.
        DB::table('lab_main_works')->where('redo_quantity', '>', 0)->update([
            'payable_quantity' => DB::raw('quantity - redo_quantity'),
        ]);

        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->dropColumn('redo_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->unsignedInteger('redo_quantity')->default(0);
        });

        DB::table('lab_main_works')->whereNotNull('payable_quantity')->update([
            'redo_quantity' => DB::raw('quantity - payable_quantity'),
        ]);

        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->dropColumn('payable_quantity');
        });
    }
};
