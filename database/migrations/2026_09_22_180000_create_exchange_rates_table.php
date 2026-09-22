<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency', 3);
            $table->decimal('rate_to_gel', 18, 6);
            $table->string('source', 16)->default('NBG');
            $table->date('effective_date');
            $table->timestamps();
            $table->unique(['currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
