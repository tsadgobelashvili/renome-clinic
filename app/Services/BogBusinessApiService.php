<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BogBusinessApiService
{
    /** @return array{status: int, records: array} */
    public function statement(string $startDate, string $endDate, bool $redactOutput = true, bool $includeToday = false): array
    {
        $settings = config('services.bog');
        $token = $this->accessToken();
        try {
            $path = implode('/', array_map('rawurlencode', [
                $settings['account_number'], $settings['account_currency'], $startDate, $endDate,
            ]));
            if ($includeToday) {
                $path .= '/true/true/10000';
            }
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(10)->timeout(30)
                ->withToken($token)->get('https://api.businessonline.ge/api/v2/statement/'.$path);
        } catch (ConnectionException) {
            // Do not expose request headers, credentials, or raw transport exceptions.
            throw new RuntimeException('Could not connect to BOG or the request timed out. Check network access and try again.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('BOG statement request failed (HTTP '.$response->status().'). Check account, currency, dates and API permissions.');
        }
        $payload = $response->json();
        $records = is_array($payload) && array_is_list($payload) ? $payload
            : ($payload['Records'] ?? $payload['records'] ?? $payload['Transactions'] ?? $payload['transactions'] ?? null);
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('BOG returned an unexpected statement format (HTTP '.$response->status().'); expected a transaction list or Records array.');
        }
        if (($payload['totalCount'] ?? $payload['TotalCount'] ?? count($records)) > count($records)) {
            throw new RuntimeException('BOG returned an incomplete statement. Retry a smaller date range; no partial statement was imported.');
        }

        // The diagnostic prints transaction data only, never the authentication response.
        $secrets = [$settings['client_secret'], $token, base64_encode($settings['client_id'].':'.$settings['client_secret'])];

        return ['status' => $response->status(), 'records' => $redactOutput ? $this->redact($records, $secrets) : $records];
    }

    /** Account metadata/balances, using the same credentials and token flow as statements. */
    public function currentBalance(): string
    {
        $token = $this->accessToken();
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(10)->timeout(30)
                ->withToken($token)->get('https://api.businessonline.ge/api/accounts/'.rawurlencode(config('services.bog.account_number')).'/'.rawurlencode(config('services.bog.account_currency')).'/false');
        } catch (ConnectionException) {
            throw new RuntimeException('BOG account balance is temporarily unavailable.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('BOG account request failed (HTTP '.$response->status().').');
        }
        $balance = $response->json('CurrentBalance') ?? $response->json('currentBalance');
        if (! is_numeric($balance)) {
            throw new RuntimeException('BOG returned no current account balance.');
        }

        return number_format((float) $balance, 2, '.', '');
    }

    private function accessToken(): string
    {
        $settings = config('services.bog');
        foreach (['client_id', 'client_secret', 'account_number', 'account_currency'] as $key) {
            if (blank($settings[$key] ?? null)) {
                $field = 'BOG_'.strtoupper($key);
                Log::warning('BOG API configuration is incomplete.', ['missing_field' => $field]);
                throw new RuntimeException(__('bog-transactions.configuration_missing', ['field' => $field]));
            }
        }

        try {
            $auth = Http::acceptJson()->asForm()->withoutRedirecting()->connectTimeout(10)->timeout(30)
                ->withBasicAuth($settings['client_id'], $settings['client_secret'])
                ->post('https://account.bog.ge/auth/realms/bog/protocol/openid-connect/token', [
                    'grant_type' => 'client_credentials',
                ]);
            if (! $auth->successful()) {
                throw new RuntimeException('BOG token request failed (HTTP '.$auth->status().'). Check credentials and API access.');
            }
            $token = $auth->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('BOG token response has no access token (HTTP '.$auth->status().').');
            }

            return $token;
        } catch (ConnectionException) {
            throw new RuntimeException('Could not connect to BOG or the request timed out. Check network access and try again.');
        }
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_match('/token|secret|authorization/i', (string) $key)
                    ? '[redacted]' : $this->redact($item, $secrets);
            }

            return $value;
        }

        return is_string($value) ? str_replace($secrets, '[redacted]', $value) : $value;
    }
}
