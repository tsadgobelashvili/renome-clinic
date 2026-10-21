<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;

/** Private, disk-backed import checkpoints. Not an accounting or classification database. */
class RsImportWorkspace
{
    private PDO $db;

    private array $statements = [];

    public readonly string $path;

    public function __construct(public readonly string $id, bool $create = false)
    {
        abort_unless(Str::isUuid($id), 404);
        $disk = Storage::disk('local');
        $relative = 'purchase-import-workspaces/'.$id.'.sqlite';
        if ($create) {
            $disk->makeDirectory('purchase-import-workspaces');
        } else {
            abort_unless($disk->exists($relative), 404);
        }
        $this->path = $disk->path($relative);
        $this->db = new PDO('sqlite:'.$this->path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA cache_size = -2048; PRAGMA temp_store = FILE; PRAGMA busy_timeout = 5000');
        if ($create) {
            $this->db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
                CREATE TABLE inputs (seq INTEGER PRIMARY KEY, body TEXT NOT NULL);
                CREATE TABLE occurrences (key TEXT PRIMARY KEY, n INTEGER NOT NULL);
                CREATE TABLE touched (id INTEGER PRIMARY KEY, finalized INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE issues (scope TEXT NOT NULL, reason TEXT NOT NULL, UNIQUE(scope, reason));
                CREATE TABLE aliases (scope TEXT PRIMARY KEY, target TEXT NOT NULL);
                CREATE INDEX aliases_target ON aliases(target)');
        }
    }

    public static function temporary(): self
    {
        return new self((string) Str::uuid(), create: true);
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->statements[$sql] ??= $this->db->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();

            return $result;
        } catch (\Throwable $exception) {
            foreach ($this->statements as $statement) {
                $statement->closeCursor();
            }
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $statement = $this->query('SELECT value FROM meta WHERE key = ?', [$key]);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? $default : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function set(string $key, mixed $value): void
    {
        $this->query('INSERT INTO meta(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
    }

    public function occurrence(string $key): int
    {
        $statement = $this->query('INSERT INTO occurrences(key, n) VALUES (?, 1) ON CONFLICT(key) DO UPDATE SET n = n + 1 RETURNING n', [$key]);
        $value = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    public function issue(string $scope, string $reason): void
    {
        $this->query('INSERT OR IGNORE INTO issues(scope, reason) VALUES (?, ?)', [$scope, $reason]);
    }

    public function errors(string $scope): array
    {
        return $this->query('SELECT DISTINCT reason FROM issues WHERE scope = ? OR scope IN (SELECT scope FROM aliases WHERE target = ?) LIMIT 20', [$scope, $scope])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function touch(int $id): void
    {
        $this->query('INSERT OR IGNORE INTO touched(id) VALUES (?)', [$id]);
    }

    public function unfinished(int $limit = 50): array
    {
        return $this->query('SELECT id FROM touched WHERE finalized = 0 ORDER BY id LIMIT '.(int) $limit)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function remove(): void
    {
        $this->statements = [];
        unset($this->db);
        Storage::disk('local')->delete('purchase-import-workspaces/'.$this->id.'.sqlite');
    }
}
