<?php

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;

/** Retains Filament authentication; adds a short, failure-only account cooldown. */
class Login extends \Filament\Auth\Pages\Login
{
    public const ACCOUNT_ATTEMPTS = 10;

    public const ACCOUNT_COOLDOWN_SECONDS = 300;

    public function authenticate(): ?LoginResponse
    {
        if (RateLimiter::tooManyAttempts($this->accountKey(), self::ACCOUNT_ATTEMPTS)) {
            // Blocked accounts still consume the existing 5/minute IP allowance.
            try {
                $this->rateLimit(5);
            } catch (TooManyRequestsException $exception) {
                $this->getRateLimitedNotification($exception)?->send();

                return null;
            }

            // Identical response for known, unknown, inactive and cooling accounts.
            app(Timebox::class)->call(fn () => parent::throwFailureValidationException(),
                (int) config('auth.timebox_duration', 200_000));
        }

        $response = parent::authenticate();
        if ($response !== null) {
            RateLimiter::clear($this->accountKey());
        }

        return $response;
    }

    protected function throwFailureValidationException(): never
    {
        RateLimiter::hit($this->accountKey(), self::ACCOUNT_COOLDOWN_SECONDS);

        parent::throwFailureValidationException();
    }

    private function accountKey(): string
    {
        $email = $this->data['email'] ?? '';
        $normalized = is_string($email) ? mb_strtolower(trim($email)) : '';

        // No raw email, password or reusable password hash in limiter keys.
        return 'erp-login-account:'.hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
