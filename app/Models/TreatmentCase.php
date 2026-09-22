<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TreatmentCase extends Model
{
    public const CONSULTATION_CATEGORIES = ['consultation', 'tomography'];

    public const STATISTICS_GROUPS = [
        'filling' => 'დაბჟენა',
        'endodontics' => 'ენდოდონტია',
        'cleaning' => 'წმენდა',
        'whitening' => 'გათეთრება',
        'medication' => 'წამლის მოთავსება',
        'extraction' => 'ექსტრაქცია',
        'implantation' => 'იმპლანტაცია',
        'augmentation' => 'აუგმენტაცია',
        'sinus_lift' => 'სინუს ლიფტინგი',
        'zircon' => 'ცირკონი',
        'pmma' => 'PMMA / დროებითი გვირგვინი',
        'prosthesis' => 'პროთეზი',
        'other' => 'სხვა',
    ];

    public const STATISTICS_GROUP_CATEGORIES = [
        'filling' => 'therapy',
        'cleaning' => 'therapy',
        'endodontics' => 'therapy',
        'whitening' => 'therapy',
        'medication' => 'therapy',
        'implantation' => 'surgery',
        'extraction' => 'surgery',
        'sinus_lift' => 'surgery',
        'augmentation' => 'surgery',
        'zircon' => 'orthopedics',
        'pmma' => 'orthopedics',
        'prosthesis' => 'orthopedics',
    ];

    public const CATEGORIES = [
        'other' => 'სხვა',
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
        'statistics_group',
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
            $treatment->statistics_group = filled($treatment->statistics_group)
                ? trim((string) $treatment->statistics_group)
                : null;

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

            if ($treatment->statistics_group !== null && ! array_key_exists($treatment->statistics_group, self::STATISTICS_GROUPS)) {
                throw ValidationException::withMessages([
                    'statistics_group' => 'აირჩიეთ სტატისტიკის სწორი ჯგუფი.',
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

    public static function inferStatisticsGroup(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return match (true) {
            str_contains($name, 'დაბჟ'), str_contains($name, 'filling'), str_contains($name, 'composite') => 'filling',
            str_contains($name, 'ენდოდ'), str_contains($name, 'არხ'), str_contains($name, 'endodont'), str_contains($name, 'root canal') => 'endodontics',
            str_contains($name, 'წმენდ'), str_contains($name, 'cleaning'), str_contains($name, 'scaling'), str_contains($name, 'hygiene') => 'cleaning',
            str_contains($name, 'გათეთრ'), str_contains($name, 'whiten'), str_contains($name, 'bleach') => 'whitening',
            str_contains($name, 'წამლ'), str_contains($name, 'მედიკამენტ'), str_contains($name, 'medication'), str_contains($name, 'medicament') => 'medication',
            str_contains($name, 'ექსტრაქ'), str_contains($name, 'ამოღებ'), str_contains($name, 'extract') => 'extraction',
            str_contains($name, 'იმპლანტაცია'), str_contains($name, 'implantation') => 'implantation',
            str_contains($name, 'აუგმენტაცია'), str_contains($name, 'augmentation') => 'augmentation',
            str_contains($name, 'სინუს'), str_contains($name, 'sinus') => 'sinus_lift',
            str_contains($name, 'ცირკონ'), str_contains($name, 'zircon') => 'zircon',
            str_contains($name, 'pmma'), str_contains($name, 'დროებით გვირგვინ'), str_contains($name, 'temporary crown'), str_contains($name, 'provisional crown') => 'pmma',
            str_contains($name, 'პროთეზ'), str_contains($name, 'denture'), str_contains($name, 'prosthesis') => 'prosthesis',
            default => 'other',
        };
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
