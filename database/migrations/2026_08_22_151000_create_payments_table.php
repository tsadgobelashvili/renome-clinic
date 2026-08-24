<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
