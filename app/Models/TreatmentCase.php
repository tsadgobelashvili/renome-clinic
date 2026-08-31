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
        'consultation' => 'კონსულტაცია',
        'tomography' => 'ტომოგრაფია',
        'pediatric_dentistry' => 'ბავშვთა',
    ];

    protected $fillable = [
        'name',
        'category',
        'triggers_owner_split',
        'default_price',
        'is_active',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'triggers_owner_split' => 'boolean',
            'default_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TreatmentCase $treatment): void {
            if (! $treatment->exists && ! $treatment->triggers_owner_split) {
                $name = mb_strtolower(trim((string) $treatment->name));
                $treatment->triggers_owner_split = str($name)->startsWith([
                    'implantation', 'იმპლანტაცია', 'sinus', 'სინუს', 'augmentation', 'აუგმენტაცია',
                ]);
            }

            if ($treatment->default_price !== null && (float) $treatment->default_price < 0) {
                throw ValidationException::withMessages([
                    'default_price' => 'ფასი უარყოფითი ვერ იქნება.',
                ]);
            }

            if (! array_key_exists((string) $treatment->category, self::categoryOptions())) {
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

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        $databaseCategories = static::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->mapWithKeys(fn (string $category): array => [
                $category => self::CATEGORIES[$category] ?? $category,
            ])
            ->all();

        return self::CATEGORIES + $databaseCategories;
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
