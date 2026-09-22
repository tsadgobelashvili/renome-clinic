<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NbgExchangeRate
{
    /** @return array<string, float> */
    public function usdGelForDates(array $dates, bool $fetchMissing = true): array
    {
        $dates = array_values(array_unique($dates));
        validator(['dates' => $dates], ['dates.*' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();
        if ($dates === []) {
            return [];
        }
        $stored = fn () => ExchangeRate::query()->where('currency', 'USD')->where('source', 'NBG')
            ->whereIn('date', $dates)->pluck('rate_to_gel', 'date')->map(fn ($rate) => (float) $rate)->all();
        $rates = $stored();
        $missing = array_values(array_diff($dates, array_keys($rates)));
        if ($missing === []) {
            return $rates;
        }
        if (! $fetchMissing) {
            throw new RuntimeException('Missing stored NBG rates. Run php artisan exchange-rates:backfill.');
        }
        // One persisted row per activity date, even when its effective official date is earlier.
        $pending = array_combine($missing, $missing);
        $resolved = [];
        for ($lookback = 0; $pending !== [] && $lookback <= 31; $lookback++) {
            foreach (array_chunk(array_values(array_unique($pending)), 5) as $chunk) {
                $responses = Http::pool(fn (Pool $pool) => array_map(fn ($date) => $pool->as($date)->timeout(5)->get(
                    'https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/en/json/',
                    ['currencies' => 'USD', 'date' => $date],
                ), $chunk));
                foreach ($chunk as $lookupDate) {
                    $response = $responses[$lookupDate] ?? null;
                    if (! $response instanceof Response || ! $response->successful()) {
                        throw new RuntimeException("NBG unavailable for {$lookupDate}; no substitute rate was stored.");
                    }
                    $payload = $response->json('0');
                    $usd = collect($payload['currencies'] ?? [])->firstWhere('code', 'USD');
                    foreach ($pending as $date => $lookup) {
                        if ($lookup !== $lookupDate) {
                            continue;
                        }
                        if ($payload === null || $usd === null) {
                            $pending[$date] = CarbonImmutable::parse($lookupDate)->subDay()->toDateString();

                            continue;
                        }
                        $effective = substr($usd['validFromDate'] ?? '', 0, 10);
                        if (! is_numeric($usd['rate'] ?? null) || ! is_numeric($usd['quantity'] ?? null)
                            || $usd['rate'] <= 0 || $usd['quantity'] <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)
                            || $effective > $lookupDate || substr($payload['date'] ?? '', 0, 10) > $lookupDate) {
                            throw new RuntimeException("Invalid NBG USD rate for {$lookupDate}.");
                        }
                        $resolved[] = ['date' => $date, 'currency' => 'USD', 'rate_to_gel' => round($usd['rate'] / $usd['quantity'], 6),
                            'source' => 'NBG', 'effective_date' => $effective, 'created_at' => now(), 'updated_at' => now()];
                        unset($pending[$date]);
                    }
                }
            }
            if ($resolved !== []) {
                ExchangeRate::query()->insertOrIgnore($resolved);
                $resolved = [];
            }
        }
        if ($pending !== []) {
            throw new RuntimeException('No official NBG rate found in the previous 31 days for: '.implode(', ', array_keys($pending)));
        }

        // A concurrent backfill may have inserted first; always return the persisted values.
        return $stored();
    }

    public function usdGel(): float
    {
        return (float) Cache::remember(
            'nbg:official-rate:USD:'.today()->toDateString(),
            now()->endOfDay(),
            fn (): float => $this->fetchUsdGel(),
        );
    }

    private function fetchUsdGel(): float
    {
        $response = Http::timeout(5)
            ->withHeaders(['SOAPAction' => 'http://www.nbg.ge/GetCurrentRates'])
            ->withBody($this->soapRequest(), 'text/xml; charset=utf-8')
            ->post(config('services.nbg.rates_url'));

        if (! $response->successful()) {
            throw new RuntimeException('NBG exchange-rate service is unavailable.');
        }

        $xml = @simplexml_load_string($response->body());
        $rate = $xml?->xpath("//*[local-name()='CurrencyRate'][*[local-name()='Code']='USD']/*[local-name()='Rate']")[0] ?? null;
        $quantity = $xml?->xpath("//*[local-name()='CurrencyRate'][*[local-name()='Code']='USD']/*[local-name()='Quantity']")[0] ?? null;

        if ((float) $rate <= 0 || (int) $quantity <= 0) {
            throw new RuntimeException('NBG response does not contain a valid USD rate.');
        }

        return round((float) $rate / (int) $quantity, 6);
    }

    private function soapRequest(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <GetCurrentRates xmlns="http://www.nbg.ge/">
      <Currencies>USD</Currencies>
    </GetCurrentRates>
  </soap:Body>
</soap:Envelope>
XML;
    }
}
