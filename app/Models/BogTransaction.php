<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BogTransaction extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'unreviewed'];

    protected function casts(): array
    {
        return [
            'operation_date' => 'date', 'value_date' => 'date',
            'debit' => 'decimal:2', 'credit' => 'decimal:2', 'raw_payload' => 'array',
        ];
    }
}
