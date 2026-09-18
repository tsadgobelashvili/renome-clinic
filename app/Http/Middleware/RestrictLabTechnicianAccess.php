<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Retained for the Lab landing redirect; authorization belongs to policies and page Gates. */
class RestrictLabTechnicianAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user && ! $user->is_active, 403);

        if ($user?->isLabTechnician() && $request->routeIs('filament.admin.pages.dashboard')) {
            return new RedirectResponse(Filament::getPanel('admin')->getHomeUrl());
        }

        return $next($request);
    }
}
