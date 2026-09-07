<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\PatientGroup;
use Illuminate\Console\Command;

class NormalizeIsraeliPatientNames extends Command
{
    protected $signature = 'patients:normalize-israeli-names {--apply : Save the normalized names}';

    protected $description = 'Preview or normalize existing Latin Israeli patient names';

    public function handle(): int
    {
        $changed = 0;
        Patient::query()
            ->where('patient_group_id', PatientGroup::israelPartnerId())
            ->orderBy('id')
            ->chunkById(200, function ($patients) use (&$changed): void {
                foreach ($patients as $patient) {
                    $firstName = Patient::normalizeIsraeliLatinName($patient->first_name);
                    $lastName = Patient::normalizeIsraeliLatinName($patient->last_name);
                    if ($firstName === $patient->first_name && $lastName === $patient->last_name) {
                        continue;
                    }

                    $changed++;
                    $this->line("#{$patient->getKey()}: {$patient->full_name} -> {$firstName} {$lastName}");
                    if ($this->option('apply')) {
                        $patient->update(['first_name' => $firstName, 'last_name' => $lastName]);
                    }
                }
            });

        $verb = $this->option('apply') ? 'Updated' : 'Would update';
        $this->info("{$verb} {$changed} Israeli patient(s).");

        return self::SUCCESS;
    }
}
