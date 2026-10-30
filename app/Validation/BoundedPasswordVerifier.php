<?php

namespace App\Validation;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\NotPwnedVerifier;

/** Native k-anonymous verification, without logging exceptions containing form data. */
class BoundedPasswordVerifier extends NotPwnedVerifier
{
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory->withHeaders(['Add-Padding' => 'true'])
                ->connectTimeout(1)->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);

            if ($response->successful()) {
                $lines = collect(explode("\n", $response->body()))->map(fn ($line) => trim($line))
                    ->filter(fn ($line) => preg_match('/\A[A-F0-9]{35}:[0-9]+\z/', $line));
                if ($lines->isNotEmpty()) {
                    return $lines;
                }
            }
        } catch (Exception) {
            // Never report the exception/trace, password, hash prefix or account.
        }

        Log::warning('Password breach service unavailable; local password rules remain enforced.');

        return collect();
    }
}
