<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientGroup extends Model
{
    public const CLINIC_SLUG = 'clinic';

    public const ISRAEL_PARTNER_SLUG = 'israel-partner';

    protected $fillable = ['name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public static function clinicId(): ?int
    {
        $id = static::query()->where('slug', self::CLINIC_SLUG)->value('id');

        return $id === null ? null : (int) $id;
    }

    public static function israelPartnerId(): int
    {
        return (int) static::query()
            ->where('slug', self::ISRAEL_PARTNER_SLUG)
            ->soleValue('id');
    }
}
