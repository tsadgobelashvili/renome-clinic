<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreatmentCategory extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::creating(fn ($record) => $record->id ??= (string) Str::ulid());
        static::saving(fn ($record) => validator(['name' => $record->name], ['name' => ['required', 'string', 'max:255',
            Rule::unique('treatment_categories', 'name')->ignore($record->id),
        ]], ['name.unique' => 'ასეთი კატეგორია უკვე არსებობს.'])->validate());
        static::deleting(function ($record) {
            if ($record->groups()->exists() || TreatmentCase::where('category', $record->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'გამოყენებული კატეგორიის წაშლა შეუძლებელია.']);
            }
        });
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TreatmentStatisticsGroup::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentCase::class, 'category');
    }
}
