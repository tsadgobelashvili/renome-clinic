<?php

namespace App\Console\Commands;

use App\Models\RsImportCheckpoint;
use App\Services\RsImportWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PruneRsImports extends Command
{
    protected $signature = 'rs:imports-prune';

    protected $description = 'Remove expired completed/temporary RS checkpoints and their private files; never active imports';

    public function handle(): int
    {
        $removed = 0;
        RsImportCheckpoint::whereIn('status', ['completed', 'temporary', 'expired'])->where('expires_at', '<=', now())
            ->chunkById(100, function ($checkpoints) use (&$removed): void {
                foreach ($checkpoints as $checkpoint) {
                    $work = new RsImportWorkspace($checkpoint->id);
                    $lock = fopen($work->path.'.lock', 'c');
                    if (! $lock) {
                        continue;
                    }
                    try {
                        if (! flock($lock, LOCK_EX | LOCK_NB)) {
                            continue;
                        }
                        $checkpoint->refresh();
                        if (! in_array($checkpoint->status, ['completed', 'temporary', 'expired'], true)
                            || ! $checkpoint->expires_at || $checkpoint->expires_at->isFuture()) {
                            continue;
                        }
                        $paths = array_filter([$checkpoint->source_path, $checkpoint->staged_path,
                            $checkpoint->error_report_path, 'purchase-import-workspaces/'.$checkpoint->id.'.jsonl.tmp']);
                        if (Storage::disk('local')->delete($paths)) {
                            $checkpoint->delete();
                            $removed++;
                        }
                    } finally {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                    if (! RsImportCheckpoint::find($checkpoint->id)) {
                        Storage::disk('local')->delete('purchase-import-workspaces/'.$checkpoint->id.'.lock');
                    }
                }
            });
        $this->info('Removed RS checkpoints: '.$removed);
        $this->pruneOrphans();

        return self::SUCCESS;
    }

    private function pruneOrphans(): void
    {
        $disk = Storage::disk('local');
        foreach (['purchase-imports', 'purchase-import-workspaces', 'purchase-import-reports'] as $directory) {
            foreach ($disk->files($directory) as $path) {
                // Never inspect, convert or delete legacy database files.
                if (! preg_match('/\A([0-9a-f-]{36})\.(?:csv|xlsx|jsonl(?:\.tmp)?|lock)\z/', basename($path), $match)
                    || ! Str::isUuid($match[1])
                    || $disk->lastModified($path) > now()->subDays(30)->timestamp) {
                    continue;
                }
                $referenced = RsImportCheckpoint::where('id', $match[1])->orWhere('source_path', $path)
                    ->orWhere('staged_path', $path)->orWhere('error_report_path', $path)->exists();
                if (! $referenced) {
                    $disk->delete($path);
                }
            }
        }
    }
}
