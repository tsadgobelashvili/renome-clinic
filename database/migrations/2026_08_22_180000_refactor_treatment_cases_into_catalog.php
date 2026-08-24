<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
        });

        $legacyNames = [
            'implantology' => 'იმპლანტაცია',
            'zirconia' => 'ცირკონი / ორთოპედია',
            'orthodontics' => 'ორთოდონტია',
            'therapy' => 'თერაპია',
            'surgery' => 'ქირურგია',
            'other' => 'სხვა',
        ];

        DB::table('treatment_cases')
            ->orderBy('id')
            ->each(function ($treatmentCase) use ($legacyNames): void {
                $name = $legacyNames[$treatmentCase->case_type] ?? $treatmentCase->case_type;

                DB::table('treatment_cases')
                    ->where('id', $treatmentCase->id)
                    ->update(['name' => $name]);

                if (blank($treatmentCase->teeth)) {
                    return;
                }

                DB::table('visit_treatment_cases')
                    ->where('treatment_case_id', $treatmentCase->id)
                    ->whereNull('teeth')
                    ->orderBy('id')
                    ->each(function ($item) use ($treatmentCase): void {
                        $teeth = trim((string) $treatmentCase->teeth);
                        $comment = blank($item->comment) ? null : trim((string) $item->comment);
                        $fingerprint = hash('sha256', json_encode([
                            (int) $item->treatment_case_id,
                            (int) $item->quantity,
                            $teeth,
                            $comment,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                        $wouldDuplicateAnotherItem = DB::table('visit_treatment_cases')
                            ->where('visit_id', $item->visit_id)
                            ->where('fingerprint', $fingerprint)
                            ->where('id', '<>', $item->id)
                            ->exists();

                        if ($wouldDuplicateAnotherItem) {
                            return;
                        }

                        DB::table('visit_treatment_cases')
                            ->where('id', $item->id)
                            ->update([
                                'teeth' => $teeth,
                                'fingerprint' => $fingerprint,
                                'updated_at' => now(),
                            ]);
                    });
            });

        Schema::table('treatment_cases', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropConstrainedForeignId('patient_id');
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropColumn(['case_type', 'status', 'teeth']);
        });
    }

    public function down(): void
    {
        if (DB::table('treatment_cases')->exists()) {
            throw new RuntimeException('Cannot safely restore patient-specific Treatment Cases from the reusable catalog.');
        }

        Schema::table('treatment_cases', function (Blueprint $table) {
            $table->foreignId('patient_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('case_type');
            $table->string('status')->default('in_progress');
            $table->string('teeth')->nullable();
            $table->dropColumn(['name', 'is_active']);
        });
    }
};
