<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictLabTechnicianAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user && ! $user->is_active, 403);

        if ($user?->isLabTechnician() && $request->routeIs('filament.admin.pages.dashboard')) {
            return redirect()->to(\Filament\Facades\Filament::getPanel('admin')->getHomeUrl());
        }

        if ($user?->isLabTechnician()
            && ! $request->is('lab-cases*', 'logout', 'profile*')) {
            abort(403);
        }

        if ($user?->isAdministrator() && $request->is(
            'finance*',
            'direct-expenses*',
            'partner-finance*',
            'purchases*',
            'product-materials*',
            'lab-*',
            'users*',
        )) {
            abort(403);
        }

        return $next($request);
    }
}
