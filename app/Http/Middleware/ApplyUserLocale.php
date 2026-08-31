<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;
        if (in_array($locale, ['ka', 'en'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
