<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['date', 'currency', 'rate_to_gel', 'source', 'effective_date'];

    protected function casts(): array
    {
        return ['rate_to_gel' => 'decimal:6'];
    }
}
