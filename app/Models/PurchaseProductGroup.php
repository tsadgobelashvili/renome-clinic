<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseProductGroup extends Model
{
    protected $fillable = ['name'];

    public function products(): HasMany
    {
        return $this->hasMany(PurchaseProduct::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $group): void {
            if ($group->products()->exists()) {
                throw ValidationException::withMessages(['name' => 'ჯერ გადაიტანეთ პროდუქტები სხვა ჯგუფში.']);
            }
        });
    }
}
