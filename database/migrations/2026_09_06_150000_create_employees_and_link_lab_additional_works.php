<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role', 30)->index();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('lab_additional_works', function (Blueprint $table): void {
            $table->foreignId('technician_id')->nullable()->after('quantity')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_additional_works', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('technician_id');
        });

        Schema::dropIfExists('employees');
    }
};
