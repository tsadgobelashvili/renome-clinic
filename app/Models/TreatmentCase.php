<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TreatmentCase extends Model
{
    public const CATEGORIES = [
        'surgery' => 'ქირურგია',
        'orthopedics' => 'ორთოპედია',
        'therapy' => 'თერაპია',
        'periodontology' => 'პაროდონტოლოგია',
        'orthodontics' => 'ორთოდონტია',
        'pediatric_dentistry' => 'ბავშვთა სტომატოლოგია',
        'tomography' => 'ტომოგრაფია',
    ];

    protected $fillable = [
        'name',
        'category',
        'default_price',
        'is_active',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TreatmentCase $treatment): void {
            if ($treatment->default_price !== null && (float) $treatment->default_price < 0) {
                throw ValidationException::withMessages([
                    'default_price' => 'ფასი უარყოფითი ვერ იქნება.',
                ]);
            }

            if (! array_key_exists((string) $treatment->category, self::CATEGORIES)) {
                throw ValidationException::withMessages([
                    'category' => 'აირჩიეთ მკურნალობის სწორი კატეგორია.',
                ]);
            }
        });
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function visitItems(): HasMany
    {
        return $this->hasMany(VisitTreatmentCase::class);
    }

    public function visits(): BelongsToMany
    {
        return $this->belongsToMany(Visit::class, 'visit_treatment_cases')
            ->withPivot(['quantity', 'unit_price', 'teeth', 'comment'])
            ->withTimestamps();
    }
}
