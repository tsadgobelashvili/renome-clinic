<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_splits')
            ->where('payment_method', 'transfer')
            ->update(['payment_method' => 'bank_transfer']);

        DB::table('payments')
            ->where('payment_method', 'transfer')
            ->update(['payment_method' => 'bank_transfer']);
    }

    public function down(): void
    {
        DB::table('payment_splits')
            ->where('payment_method', 'bank_transfer')
            ->update(['payment_method' => 'transfer']);

        DB::table('payments')
            ->where('payment_method', 'bank_transfer')
            ->update(['payment_method' => 'transfer']);
    }
};
