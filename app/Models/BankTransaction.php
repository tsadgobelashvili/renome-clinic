<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transaction_date' => 'datetime', 'value_date' => 'date', 'amount' => 'decimal:2', 'bank_fee' => 'decimal:2', 'gross_amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'raw_data' => 'array', 'exclude_from_pnl' => 'boolean', 'include_embedded_fee' => 'boolean', 'is_legacy' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankCategory::class, 'bank_category_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(BankImportBatch::class, 'import_batch_id');
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function expenseSubcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class);
    }

    public function categorizationRule(): BelongsTo
    {
        return $this->belongsTo(BankCategorizationRule::class);
    }
}
