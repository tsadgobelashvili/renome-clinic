<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bog_sync_states', function (Blueprint $table): void {
            $table->string('account_number', 64);
            $table->string('currency', 3);
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->primary(['account_number', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bog_sync_states');
    }
};
