<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/** Short Livewire requests over one private staged upload; no queue worker required. */
class RsHistoricalImport
{
    public function __construct(private readonly PurchaseImportService $importer) {}

    public function start(string $path, User $actor, bool $deferStaging = false): string
    {
        Gate::forUser($actor)->authorize('create', Purchase::class);
        $this->importer->preflight($path);
        $work = RsImportWorkspace::temporary();
        try {
            if ($deferStaging) {
                $source = 'purchase-import-workspaces/'.$work->id.'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
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
                $work->transaction(function () use ($work, $actor, $source): void {
                    $work->set('owner', $actor->id);
                    $work->set('summary', $this->importer->newSummary());
                    $work->set('source', $source);
                    $work->set('phase', 'preparing');
                });

                return $work->id;
            }
            $this->stage($work, $path, $actor);

            return $work->id;
        } catch (Throwable $exception) {
            if (isset($source)) {
                Storage::disk('local')->delete($source);
            }
            $work->remove();
            throw $exception;
        }
    }

    private function stage(RsImportWorkspace $work, string $path, User $actor): void
    {
        $work->transaction(function () use ($work, $path, $actor): void {
            $work->set('owner', $actor->id);
            $work->set('summary', $work->get('summary') ?? $this->importer->newSummary());
            $work->set('cursor', 0);
            $work->set('phase', 'importing');
            $sequence = 0;
            foreach ($this->importer->sourceRows($path) as $input) {
                if (($input['data']['date'] ?? null) instanceof DateTimeInterface) {
                    $input['data']['date'] = $input['data']['date']->format('Y-m-d');
                }
                $work->query('INSERT INTO inputs(seq, body) VALUES (?, ?)', [++$sequence,
                    json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
            }
            $work->set('total', $sequence);
        });
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
            if ($work->get('phase') === 'preparing') {
                $source = $work->get('source');
                $this->stage($work, Storage::disk('local')->path($source), $actor);
                Storage::disk('local')->delete($source);
            }
            $work->transaction(function () use ($work, $actor): void {
                $summary = $work->get('summary');
                $this->importer->useWorkspace($work, $summary['error_report_id']);
                if ($work->get('phase') === 'importing') {
                    $rows = $work->query('SELECT seq, body FROM inputs WHERE seq > ? ORDER BY seq LIMIT 500', [$work->get('cursor', 0)])->fetchAll(PDO::FETCH_ASSOC);
                    $this->importer->processInputs(array_map(fn ($row) => json_decode($row['body'], true, flags: JSON_THROW_ON_ERROR), $rows), $summary, $actor->id, $work->id);
                    if ($rows !== []) {
                        $work->set('cursor', (int) end($rows)['seq']);
                    }
                    if ($work->get('cursor') >= $work->get('total')) {
                        $work->set('phase', 'finalizing');
                    }
                }
                if ($work->get('phase') === 'finalizing' && $this->importer->finalizeDocuments($summary)) {
                    $work->set('phase', 'completed');
                }
                $work->set('summary', $summary);
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

        return $work;
    }

    private function snapshot(RsImportWorkspace $work): array
    {
        return [...$work->get('summary'), 'processed' => $work->get('cursor', 0),
            'total' => $work->get('total', 0), 'phase' => $work->get('phase')];
    }
}
