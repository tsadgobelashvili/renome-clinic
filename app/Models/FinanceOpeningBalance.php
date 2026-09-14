<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceOpeningBalance extends Model
{
    protected $fillable = ['source', 'bank', 'account_identifier', 'currency', 'effective_date', 'amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'amount' => 'decimal:2'];
    }
}
