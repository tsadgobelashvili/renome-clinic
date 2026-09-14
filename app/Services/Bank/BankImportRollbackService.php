<?php

namespace App\Services\Bank;

use App\Models\BankImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BankImportRollbackService
{
    public function rollback(int $batchId, User $owner): int
    {
        abort_unless($owner->isOwner(), 403);

        return DB::transaction(function () use ($batchId, $owner): int {
            $batch = BankImportBatch::lockForUpdate()->findOrFail($batchId);
            if ($batch->rolled_back_at !== null) {
                return 0;
            }

            // Delete solely by this batch's FK. Dates, filenames and operation IDs are not deletion scopes.
            $count = $batch->transactions()->delete();
            $batch->update(['rolled_back_at' => now(), 'rolled_back_by' => $owner->id, 'rolled_back_rows' => $count]);

            return $count;
        });
    }
}
