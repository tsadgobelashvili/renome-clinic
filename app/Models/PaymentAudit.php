<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'user_id', 'action', 'old_values', 'new_values'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
