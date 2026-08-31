<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    public const NEEDS_REVIEW_SLUG = 'needs-review';

    protected $fillable = ['name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public static function needsReviewId(): ?int
    {
        return static::query()->where('slug', self::NEEDS_REVIEW_SLUG)->value('id');
    }

    public static function uncategorizedId(): ?int
    {
        return self::needsReviewId();
    }
}
