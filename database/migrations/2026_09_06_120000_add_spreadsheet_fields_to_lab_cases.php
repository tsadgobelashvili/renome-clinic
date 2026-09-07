<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->string('external_doctor_name')->nullable()->after('doctor_id');
            $table->string('source', 20)->nullable()->index()->after('case_date');
            $table->string('material', 20)->nullable()->index()->after('source');
            $table->unsignedInteger('quantity')->nullable()->after('material');
            $table->string('shade')->nullable()->after('quantity');
            $table->string('modeling')->nullable()->after('shade');
            $table->unsignedInteger('milling_quantity')->nullable()->after('modeling');
            $table->string('milling_technician')->nullable()->after('milling_quantity');
        });

        Schema::create('lab_additional_works', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_case_id')->constrained()->cascadeOnDelete();
            $table->string('work_type');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('technician')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_additional_works');

        Schema::table('lab_cases', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropIndex(['material']);
            $table->dropColumn([
                'external_doctor_name', 'source', 'material', 'quantity', 'shade',
                'modeling', 'milling_quantity', 'milling_technician',
            ]);
        });
    }
};
