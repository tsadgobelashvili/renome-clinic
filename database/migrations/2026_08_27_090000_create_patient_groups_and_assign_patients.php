<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('patient_groups')->insert([
            ['name' => 'Clinic', 'slug' => 'clinic', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Israel Partner', 'slug' => 'israel-partner', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('patients', function (Blueprint $table): void {
            $table->foreignId('patient_group_id')->nullable()->after('patient_number')
                ->constrained('patient_groups')->restrictOnDelete();
        });

        $clinicId = DB::table('patient_groups')->where('slug', 'clinic')->value('id');
        DB::table('patients')->whereNull('patient_group_id')->update(['patient_group_id' => $clinicId]);

        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('patient_group_id')->nullable(false)->change();
            $table->index('patient_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['patient_group_id']);
            $table->dropConstrainedForeignId('patient_group_id');
        });
        Schema::dropIfExists('patient_groups');
    }
};
