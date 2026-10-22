<?php

namespace App\Services;

use App\Models\RsImportCheckpoint;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Laravel database metadata and private JSONL; never a separate database connection. */
class RsImportWorkspace
{
    public readonly string $path;

    public RsImportCheckpoint $checkpoint;

    public function __construct(public readonly string $id, bool $create = false)
    {
        abort_unless(Str::isUuid($id), 404);
        $this->checkpoint = $create
            ? RsImportCheckpoint::create(['id' => $id, 'metadata' => [], 'expires_at' => now()->addDay()])
            : RsImportCheckpoint::findOrFail($id);
        $disk = Storage::disk('local');
        if (! $disk->makeDirectory('purchase-import-workspaces')) {
            throw new \RuntimeException('Unable to create private RS workspace directory.');
        }
        $this->path = $disk->path('purchase-import-workspaces/'.$id);
    }

    public static function temporary(): self
    {
        return new self((string) Str::uuid(), create: true);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $column = $this->column($key);

        return $column ? ($this->checkpoint->{$column} ?? $default) : ($this->checkpoint->metadata[$key] ?? $default);
    }

    private function column(string $key): ?string
    {
        return ['owner' => 'owner_id', 'phase' => 'status', 'cursor' => 'cursor', 'total' => 'total_rows',
            'offset' => 'byte_offset', 'source' => 'source_path', 'staged' => 'staged_path'][$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($column = $this->column($key)) {
            $this->checkpoint->{$column} = $value;
        } else {
            $this->checkpoint->metadata = array_replace($this->checkpoint->metadata ?? [], [$key => $value]);
        }
        if ($key === 'summary') {
            $this->checkpoint->fill(['imported_count' => $value['imported'], 'duplicate_count' => $value['skipped'],
                'rejected_count' => $value['failed_rows'], 'error_report_path' => app(RsImportReport::class)->path($value['error_report_id'])]);
        }
        if ($key === 'phase' && $value === 'completed') {
            $this->checkpoint->completed_at = now();
            $this->checkpoint->expires_at = now()->addDays(30);
        } elseif ($key === 'phase' && in_array($value, ['preparing', 'importing', 'finalizing'], true)) {
            $this->checkpoint->started_at ??= now();
            $this->checkpoint->expires_at = null;
        }
        $this->checkpoint->save();
    }

    public function entries(string $kind): Builder
    {
        return DB::table('rs_import_checkpoint_entries')->where('checkpoint_id', $this->id)->where('kind', $kind);
    }

    public function occurrence(string $key): int
    {
        $key = hash('sha256', $key);
        $this->entries('occurrence')->insertOrIgnore(['checkpoint_id' => $this->id, 'kind' => 'occurrence', 'entry_key' => $key]);
        $this->entries('occurrence')->where('entry_key', $key)->increment('number');

        return (int) $this->entries('occurrence')->where('entry_key', $key)->value('number');
    }

    public function issue(string $scope, string $reason): void
    {
        $this->entries('issue')->insertOrIgnore(['checkpoint_id' => $this->id, 'kind' => 'issue',
            'entry_key' => hash('sha256', json_encode([$scope, $reason], JSON_THROW_ON_ERROR)), 'label' => $scope, 'value' => $reason]);
    }

    public function alias(string $scope, string $target): void
    {
        $this->entries('alias')->updateOrInsert(['checkpoint_id' => $this->id, 'kind' => 'alias', 'entry_key' => hash('sha256', $scope)],
            ['label' => $scope, 'value' => $target]);
    }

    public function errors(string $scope): array
    {
        return $this->entries('issue')->where(fn ($query) => $query->where('label', $scope)
            ->orWhereIn('label', $this->entries('alias')->where('value', $scope)->select('label')))
            ->distinct()->limit(20)->pluck('value')->all();
    }

    public function touch(int $id): void
    {
        $this->entries('touched')->insertOrIgnore(['checkpoint_id' => $this->id, 'kind' => 'touched', 'entry_key' => hash('sha256', (string) $id), 'number' => $id]);
    }

    public function finalized(int $id): void
    {
        $this->entries('touched')->where('number', $id)->update(['flag' => true]);
    }

    public function unfinished(int $limit = 50): array
    {
        return $this->entries('touched')->where('flag', false)->orderBy('number')->limit($limit)->pluck('number')->all();
    }

    public function inputs(): \Generator
    {
        $file = fopen(Storage::disk('local')->path($this->get('staged')), 'rb');
        if (! $file) {
            throw new \RuntimeException('Retained RS rows are unavailable.');
        }
        try {
            while (($line = fgets($file)) !== false) {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            fclose($file);
        }
    }

    public function remove(): void
    {
        $this->checkpoint->delete();
    }
}
