<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('show_in_lab_doctor_list')->default(false);
        });
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->foreignId('assistant_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('assistant_employee_id'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('show_in_lab_doctor_list'));
    }
};
