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

        if ($user?->isLabTechnician()
            && ! $request->is('admin/lab-cases*', 'admin/logout', 'admin/profile*')) {
            abort(403);
        }

        if ($user?->isAdministrator() && $request->is(
            'admin/finance*',
            'admin/direct-expenses*',
            'admin/partner-finance*',
            'admin/purchases*',
            'admin/product-materials*',
            'admin/lab-*',
            'admin/users*',
        )) {
            abort(403);
        }

        return $next($request);
    }
}
