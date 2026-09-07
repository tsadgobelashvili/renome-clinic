<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_technician')->default(false)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('employee_positions')->insert([
            ['name' => 'Technician', 'is_active' => true, 'is_technician' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Designer', 'is_active' => true, 'is_technician' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Administrator', 'is_active' => true, 'is_technician' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Assistant', 'is_active' => true, 'is_technician' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Manager', 'is_active' => true, 'is_technician' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other', 'is_active' => true, 'is_technician' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('employees', function (Blueprint $table): void {
            $table->date('birth_date')->nullable()->after('last_name');
            $table->string('personal_id')->nullable()->index()->after('birth_date');
            $table->foreignId('position_id')->nullable()->after('personal_id')->constrained('employee_positions')->restrictOnDelete();
        });

        DB::statement("UPDATE employees SET position_id = (SELECT id FROM employee_positions WHERE name = 'Technician') WHERE role = 'technician'");
        DB::statement("UPDATE employees SET position_id = (SELECT id FROM employee_positions WHERE name = 'Administrator') WHERE role = 'administrator'");
        DB::statement("UPDATE employees SET position_id = (SELECT id FROM employee_positions WHERE name = 'Other') WHERE position_id IS NULL");

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });

        Schema::table('lab_additional_works', function (Blueprint $table): void {
            $table->dropForeign(['technician_id']);
            $table->foreign('technician_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_additional_works', function (Blueprint $table): void {
            $table->dropForeign(['technician_id']);
            $table->foreign('technician_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('role', 30)->nullable()->index();
        });

        DB::table('employees')->orderBy('id')->eachById(function (object $employee): void {
            $position = DB::table('employee_positions')->find($employee->position_id);
            DB::table('employees')->where('id', $employee->id)->update(['role' => match ($position?->name) {
                'Technician' => 'technician',
                'Administrator' => 'administrator',
                default => 'other',
            }]);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('position_id');
            $table->dropIndex(['personal_id']);
            $table->dropColumn(['birth_date', 'personal_id']);
        });

        Schema::dropIfExists('employee_positions');
    }
};
