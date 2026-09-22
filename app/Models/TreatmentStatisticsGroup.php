<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreatmentStatisticsGroup extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'category_id'];

    protected static function booted(): void
    {
        static::creating(fn ($record) => $record->id ??= (string) Str::ulid());
        static::saving(function ($record) {
            validator(['name' => $record->name], ['name' => ['required', 'string', 'max:255',
                Rule::unique('treatment_statistics_groups', 'name')->where('category_id', $record->category_id)->ignore($record->id),
            ]], ['name.unique' => 'ამ კატეგორიაში ასეთი ჯგუფი უკვე არსებობს.'])->validate();
            if (! TreatmentCategory::whereKey($record->category_id)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'აირჩიეთ კატეგორია.']);
            }
            if ($record->exists && $record->isDirty('category_id') && $record->items()->exists()) {
                throw ValidationException::withMessages(['category_id' => 'გამოყენებული ჯგუფის კატეგორიის შეცვლა შეუძლებელია.']);
            }
        });
        static::deleting(function ($record) {
            if ($record->items()->exists()) {
                throw ValidationException::withMessages(['name' => 'გამოყენებული ჯგუფის წაშლა შეუძლებელია.']);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TreatmentCategory::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentCase::class, 'statistics_group');
    }
}
