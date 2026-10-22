<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RsImportCheckpoint extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'owner_id' => 'integer', 'total_rows' => 'integer', 'cursor' => 'integer',
            'byte_offset' => 'integer', 'imported_count' => 'integer', 'duplicate_count' => 'integer',
            'rejected_count' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
