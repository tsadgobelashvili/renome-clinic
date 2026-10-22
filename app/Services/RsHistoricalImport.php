<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\RsImportCheckpoint;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Short Livewire requests over private source/JSONL files and Laravel DB metadata. */
class RsHistoricalImport
{
    public function __construct(private readonly PurchaseImportService $importer) {}

    public function start(string $path, User $actor, bool $deferStaging = false, ?string $originalFilename = null): string
    {
        Gate::forUser($actor)->authorize('create', Purchase::class);
        $this->importer->preflight($path);
        $work = RsImportWorkspace::temporary();
        $source = 'purchase-import-workspaces/'.$work->id.'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            $stream = fopen($path, 'rb');
            try {
                if (! $stream || ! Storage::disk('local')->put($source, $stream)) {
                    throw new \RuntimeException('Unable to persist RS checkpoint source.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $work->transaction(function () use ($work, $actor, $source, $path, $originalFilename): void {
                $work->checkpoint->fill(['original_filename' => $originalFilename ?? basename($path),
                    'file_hash' => hash_file('sha256', $path)]);
                $work->set('owner', $actor->id);
                $work->set('source', $source);
                $work->set('summary', $this->importer->newSummary());
                $work->set('phase', 'preparing');
            });
            if (! $deferStaging) {
                $this->stage($work, Storage::disk('local')->path($source));
            }

            return $work->id;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_filter([$source, $work->get('staged'), $work->checkpoint->error_report_path]));
            $work->remove();
            throw $exception;
        }
    }

    private function stage(RsImportWorkspace $work, string $path): void
    {
        if (! hash_equals($work->checkpoint->file_hash, hash_file('sha256', $path))) {
            throw new \RuntimeException('Private RS source hash changed.');
        }
        $staged = 'purchase-import-workspaces/'.$work->id.'.jsonl';
        $disk = Storage::disk('local');
        $temporary = $disk->path($staged.'.tmp');
        $file = fopen($temporary, 'wb');
        if (! $file) {
            throw new \RuntimeException('Unable to stage RS source rows.');
        }
        try {
            $sequence = 0;
            foreach ($this->importer->sourceRows($path) as $input) {
                if (($input['data']['date'] ?? null) instanceof DateTimeInterface) {
                    $input['data']['date'] = $input['data']['date']->format('Y-m-d');
                }
                $line = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file, $line) !== strlen($line)) {
                    throw new \RuntimeException('Unable to persist staged RS row.');
                }
                $sequence++;
            }
        } finally {
            fclose($file);
        }
        if (! rename($temporary, $disk->path($staged))) {
            throw new \RuntimeException('Unable to publish staged RS rows.');
        }
        $work->transaction(function () use ($work, $sequence, $staged): void {
            $work->set('staged', $staged);
            $work->set('total', $sequence);
            $work->set('cursor', 0);
            $work->set('offset', 0);
            $work->set('phase', 'importing');
        });
    }

    public function active(User $actor): ?string
    {
        Gate::forUser($actor)->authorize('create', Purchase::class);

        return RsImportCheckpoint::where('owner_id', $actor->id)->whereIn('status', ['preparing', 'importing', 'finalizing'])
            ->latest('created_at')->value('id');
    }

    public function status(string $id, User $actor): array
    {
        return $this->snapshot($this->authorized($id, $actor));
    }

    public function step(string $id, User $actor): array
    {
        $work = $this->authorized($id, $actor);
        $lock = fopen($work->path.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }

            return $this->snapshot($work);
        }
        try {
            // Another request may have completed while this request opened its lock.
            $work->checkpoint->refresh();
            if ($work->get('phase') === 'preparing') {
                $this->stage($work, Storage::disk('local')->path($work->get('source')));
            }
            $summary = $work->get('summary');
            $this->importer->useWorkspace($work, $summary['error_report_id']);
            $cursor = $work->get('cursor', 0);
            $offset = $work->get('offset', 0);
            $phase = $work->get('phase');
            if ($phase === 'importing') {
                $file = fopen(Storage::disk('local')->path($work->get('staged')), 'rb');
                if (! $file) {
                    throw new \RuntimeException('RS staged rows unavailable.');
                }
                try {
                    if (fseek($file, $offset) !== 0) {
                        throw new \RuntimeException('RS cursor unavailable.');
                    }
                    $rows = [];
                    while (count($rows) < 500 && ($line = fgets($file)) !== false) {
                        $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    }
                    $offset = ftell($file);
                    if ($offset === false || count($rows) !== min(500, $work->get('total') - $cursor)) {
                        throw new \RuntimeException('RS staged rows were truncated or changed.');
                    }
                } finally {
                    fclose($file);
                }
                // Deliberately outside a checkpoint DB transaction: keep committed
                // 100-row purchase batches if a later batch/request fails.
                $this->importer->processInputs($rows, $summary, $actor->id, $work->id);
                $cursor += count($rows);
                if ($cursor >= $work->get('total')) {
                    $phase = 'finalizing';
                }
            }
            if ($phase === 'finalizing' && $this->importer->finalizeDocuments($summary)) {
                $phase = 'completed';
            }
            $work->transaction(function () use ($work, $cursor, $offset, $phase, $summary): void {
                $work->set('cursor', $cursor);
                $work->set('offset', $offset);
                $work->set('summary', $summary);
                $work->set('phase', $phase);
            });

            return $this->snapshot($work);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function authorized(string $id, User $actor): RsImportWorkspace
    {
        Gate::forUser($actor)->authorize('create', Purchase::class);
        $work = new RsImportWorkspace($id);
        abort_unless($work->get('owner') === $actor->id, 403);
        abort_if(in_array($work->get('phase'), ['expired', 'temporary'], true), 404);

        return $work;
    }

    private function snapshot(RsImportWorkspace $work): array
    {
        return [...$work->get('summary'), 'processed' => $work->get('cursor', 0),
            'total' => $work->get('total', 0), 'phase' => $work->get('phase')];
    }
}
