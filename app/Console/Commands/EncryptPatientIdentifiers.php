<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Support\PatientIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EncryptPatientIdentifiers extends Command
{
    protected $signature = 'patients:encrypt-personal-ids {--dry-run : Validate and count changes, then roll back}';

    protected $description = 'Encrypt legacy patient identifiers and build their exact-search indexes atomically';

    public function handle(): int
    {
        try {
            PatientIdentifier::key();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $changed = 0;
        $unchanged = 0;
        $snapshots = 0;
        DB::beginTransaction();

        try {
            DB::table('patients')->select(['id', 'personal_id', 'personal_id_hash'])
                ->orderBy('id')->lockForUpdate()->chunkById(200, function ($rows) use (&$changed, &$unchanged): void {
                    foreach ($rows as $row) {
                        $value = PatientIdentifier::decrypt($row->personal_id, $row->personal_id_hash);
                        $normalized = PatientIdentifier::normalize($value);
                        $hash = PatientIdentifier::hash($normalized);
                        if ($row->personal_id_hash !== null && ! hash_equals($row->personal_id_hash, $hash ?? '')) {
                            throw new RuntimeException('Identifier index mismatch.');
                        }
                        $encrypted = $row->personal_id !== null && str_starts_with($row->personal_id, PatientIdentifier::PREFIX);
                        if (($encrypted && $value === $normalized && $row->personal_id_hash === $hash)
                            || ($row->personal_id === null && $row->personal_id_hash === null)) {
                            $unchanged++;

                            continue;
                        }

                        // The same cast used by ordinary saves encrypts exactly once.
                        $attributes = (new Patient(['personal_id' => $normalized]))->getAttributes();
                        DB::table('patients')->where('id', $row->id)->update($attributes);
                        $changed++;
                    }
                });

            // Historical merge snapshots also contain copies of patient identity fields.
            DB::table('patient_merges')->select(['id', 'duplicate_patient_snapshot'])
                ->orderBy('id')->lockForUpdate()->chunkById(200, function ($rows) use (&$snapshots): void {
                    foreach ($rows as $row) {
                        $snapshot = json_decode($row->duplicate_patient_snapshot, true, flags: JSON_THROW_ON_ERROR);
                        $stored = $snapshot['personal_id'] ?? null;
                        if ($stored === null) {
                            continue;
                        }
                        $value = PatientIdentifier::decrypt($stored);
                        if (str_starts_with($stored, PatientIdentifier::PREFIX)) {
                            continue;
                        }
                        $snapshot['personal_id'] = PatientIdentifier::encrypt($value);
                        DB::table('patient_merges')->where('id', $row->id)->update([
                            'duplicate_patient_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ]);
                        $snapshots++;
                    }
                });

            if ($this->option('dry-run')) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable) {
            DB::rollBack();
            // Never render SQL bindings, identifiers, ciphertext, or keys in an exception.
            $this->error('Backfill failed; all changes rolled back. Check the configured keys, encrypted data integrity, and duplicate normalized IDs.');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Would migrate: ' : 'Migrated: ').$changed.'; unchanged: '.$unchanged.'.');
        $this->info(($this->option('dry-run') ? 'Would migrate audit snapshots: ' : 'Migrated audit snapshots: ').$snapshots.'.');

        return self::SUCCESS;
    }
}
