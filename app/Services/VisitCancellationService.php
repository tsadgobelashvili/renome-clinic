<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitCancellationService
{
    public function __construct(private readonly PaymentProcessor $payments) {}

    public function cancel(Visit $visit, User $user, ?string $reason = null): Visit
    {
        if (! $user->isOwner()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($visit, $user, $reason): Visit {
            $visit = Visit::withCancelled()->lockForUpdate()->findOrFail($visit->getKey());

            if ($visit->is_cancelled) {
                throw ValidationException::withMessages(['visit' => 'ვიზიტი უკვე გაუქმებულია.']);
            }

            $visit->payments()->with('splits')->lockForUpdate()->get()
                ->each(fn ($payment) => $this->payments->void($payment));

            $visit->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => filled($reason) ? trim($reason) : null,
            ])->save();

            return $visit;
        });
    }
}
