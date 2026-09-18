<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Pages\BasePage;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Guard newly discovered custom pages even if their author forgets canAccess(). */
class AuthorizePanelPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $controller = explode('@', (string) $request->route()?->getAction('controller'))[0];

        if (is_subclass_of($controller, BasePage::class) && ! is_subclass_of($controller, ResourcePage::class)) {
            Gate::authorize('access-panel-page', $controller);
        }

        return $next($request);
    }
}
