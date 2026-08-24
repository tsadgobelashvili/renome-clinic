<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('currency', 3)->default('GEL')->after('total_price');
            $table->index('currency');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('currency', 3)->default('GEL')->after('amount');
            $table->index(['currency', 'payment_date']);
        });

        Schema::table('payment_splits', function (Blueprint $table): void {
            $table->string('currency', 3)->default('GEL')->after('amount');
            $table->index(['currency', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_splits', function (Blueprint $table): void {
            $table->dropIndex(['currency', 'created_at']);
            $table->dropColumn('currency');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['currency', 'payment_date']);
            $table->dropColumn('currency');
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex(['currency']);
            $table->dropColumn('currency');
        });
    }
};
