<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private JSONL: all rejected rows, without SQL errors or stack traces. */
class RsImportReport
{
    public function start(): string
    {
        $id = (string) Str::uuid();
        Storage::disk('local')->put($this->path($id), '');

        return $id;
    }

    public function append(string $id, array $entry): void
    {
        Storage::disk('local')->append($this->path($id), json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public function path(string $id): string
    {
        return 'purchase-import-reports/'.$id.'.jsonl';
    }
}
