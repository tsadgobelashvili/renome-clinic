<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

class SecurityResponseHeaders
{
    public const ERP_HOST = 'erp.renome.ge';

    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    public static function apply(Request $request, Response $response): Response
    {
        if (self::isErpHost($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'same-origin');
        }

        if ($response->getStatusCode() >= 400 || $request->is('login')
            || ($request->is('livewire/*') && $request->isMethod('POST'))
            || $request->routeIs('patients.history.*', 'treatment-estimates.*', 'purchases.import-report')
            || $request->user() !== null) {
            // Override file-download public defaults and any stale validators.
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');
        }

        return $response;
    }

    public static function isErpHost(Request $request): bool
    {
        try {
            return $request->getHost() === self::ERP_HOST;
        } catch (SuspiciousOperationException) {
            // A malformed Host must not break the exception handler a second time.
            return false;
        }
    }
}
