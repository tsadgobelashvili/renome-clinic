<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            $table->string('owner_split_key')->nullable()->unique()->after('compensation_percentage');
        });
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->boolean('triggers_owner_split')->default(false)->index()->after('category');
        });
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('owner_split_override', 10)->nullable()->index()->after('discount_reason_note');
        });

        $levanId = DB::table('doctors')->where(function ($query): void {
            $query->whereRaw('LOWER(first_name) IN (?, ?)', ['ლევან', 'levan'])
                ->whereRaw('LOWER(last_name) IN (?, ?)', ['ბერიკაშვილი', 'berikashvili']);
        })->orderBy('id')->value('id');
        if ($levanId) {
            DB::table('doctors')->where('id', $levanId)->update(['owner_split_key' => 'levan']);
        }

        $nodarId = DB::table('doctors')->where(function ($query): void {
            $query->whereRaw('LOWER(first_name) IN (?, ?)', ['ნოდარ', 'nodar'])
                ->whereRaw('LOWER(last_name) IN (?, ?, ?)', ['ელიშაკოვი', 'elishakov', 'elishakovi']);
        })->orderBy('id')->value('id');
        if ($nodarId) {
            DB::table('doctors')->where('id', $nodarId)->update(['owner_split_key' => 'nodar']);
        }

        DB::table('treatment_cases')->where(function ($query): void {
            $query->whereRaw('LOWER(name) LIKE ?', ['implantation%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['იმპლანტაცია%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['sinus%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['სინუს%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['augmentation%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['აუგმენტაცია%']);
        })->update(['triggers_owner_split' => true]);

        Schema::create('owner_salary_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_salary_settlement_id')->constrained('salary_settlements')->restrictOnDelete();
            $table->foreignId('recipient_salary_settlement_id')->nullable()->constrained('salary_settlements')->nullOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('recipient_doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('patient_group_slug')->index();
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_salary_settlement_id', 'visit_id', 'recipient_doctor_id'], 'owner_salary_share_source_unique');
            $table->index(['recipient_doctor_id', 'status', 'currency'], 'owner_salary_share_pending_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_salary_shares');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn('owner_split_override'));
        Schema::table('treatment_cases', fn (Blueprint $table) => $table->dropColumn('triggers_owner_split'));
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('owner_split_key'));
    }
};
