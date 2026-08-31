<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NbgExchangeRate
{
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
