<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait HasEffectiveTechnicianRate
{
    public function setEffectiveFromAttribute($value): void
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        Validator::make(['effective_from' => $value], ['effective_from' => ['nullable', 'date_format:Y-m-d']])->validate();
        $this->attributes['effective_from'] = $value;
    }

    protected static function bootHasEffectiveTechnicianRate(): void
    {
        static::saving(function ($rate): void {
            $rate->effective_from ??= '1900-01-01';
            Validator::make(['effective_from' => $rate->effective_from->toDateString()], [
                'effective_from' => ['required', 'date_format:Y-m-d'],
            ])->validate();
            if ($rate->exists && $rate->isDirty(array_keys($rate->getAttributes())) && $rate->historyLocked()) {
                // Timestamps alone do not change the configured rule.
                $changed = array_diff(array_keys($rate->getDirty()), ['updated_at', 'created_at']);
                if ($changed !== []) {
                    throw ValidationException::withMessages(['salaryRates' => __('employees.salary.rate_history_locked')]);
                }
            }
            $identity = $rate->only($rate->rateIdentityColumns());
            if (static::query()->where($identity)->where('effective_from', $rate->effective_from->toDateString())
                ->when($rate->exists, fn (Builder $query) => $query->whereKeyNot($rate->getKey()))->exists()) {
                throw ValidationException::withMessages(['salaryRates' => __('employees.salary.rate_date_duplicate')]);
            }
        });
        static::deleting(function ($rate): void {
            if ($rate->deletionProtected()) {
                throw ValidationException::withMessages(['salaryRates' => __('employees.salary.rate_delete_blocked')]);
            }
        });
    }

    public function delete()
    {
        // Serialize rate deletion with employee payroll finalization. Recheck the
        // persisted version, not stale repeater state, within the save transaction.
        return DB::transaction(function () {
            $owner = $this->rateIdentityColumns()[0];
            DB::table($owner === 'employee_id' ? 'employees' : 'users')
                ->where('id', $this->getRawOriginal($owner))->lockForUpdate()->first();
            $stored = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($stored?->deletionProtected()) {
                throw ValidationException::withMessages(['salaryRates' => __('employees.salary.rate_delete_blocked')]);
            }

            return parent::delete();
        });
    }

    public function nextEffectiveDate(): ?string
    {
        return static::query()->where($this->only($this->rateIdentityColumns()))
            ->where('effective_from', '>', $this->effective_from->toDateString())->min('effective_from');
    }

    public function historyLocked(): bool
    {
        return $this->exists && (string) $this->getRawOriginal('effective_from') <= today()->toDateString();
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        // Do not filter active before selecting the latest version: an inactive
        // version stops payment from its date, it must not revive an older rate.
        return $query->where('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id');
    }
}
