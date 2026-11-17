<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PartnerPaymentAudit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    protected static function booted(): void
    {
        $immutable = fn () => throw ValidationException::withMessages(['payment' => __('israeli-payments.audit_immutable')]);
        static::updating($immutable);
        static::deleting($immutable);
    }
}
