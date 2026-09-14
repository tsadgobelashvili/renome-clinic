<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankImportBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accounts' => 'array', 'currencies' => 'array', 'errors' => 'array', 'period_from' => 'date', 'period_to' => 'date', 'balance_as_of' => 'datetime', 'imported_at' => 'datetime', 'rolled_back_at' => 'datetime', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2', 'reported_balance' => 'decimal:2'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'import_batch_id');
    }
}
