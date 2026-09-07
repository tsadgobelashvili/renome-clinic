<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_settlements', function (Blueprint $table): void {
            $table->decimal('current_salary_gel', 14, 2)->nullable()->after('total_gel');
            $table->decimal('opening_carry_gel', 14, 2)->default(0)->after('current_salary_gel');
            $table->decimal('closing_carry_gel', 14, 2)->default(0)->after('opening_carry_gel');
        });

        DB::table('employee_salary_settlements')->update([
            'current_salary_gel' => DB::raw('total_gel'),
            'opening_carry_gel' => 0,
            'closing_carry_gel' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('employee_salary_settlements', fn (Blueprint $table) => $table
            ->dropColumn(['current_salary_gel', 'opening_carry_gel', 'closing_carry_gel']));
    }
};
