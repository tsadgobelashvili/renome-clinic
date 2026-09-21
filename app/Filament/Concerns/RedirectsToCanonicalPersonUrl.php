<?php

namespace App\Filament\Concerns;

trait RedirectsToCanonicalPersonUrl
{
    /** Livewire calls trait mount hooks AFTER the page's normal mount/authorization. */
    public function mountRedirectsToCanonicalPersonUrl(): void
    {
        $resource = static::getResource();
        $route = request()->route();
        // Employee pages are also inherited by the unrelated Lab Technician resource.
        if (! method_exists($resource, 'personRouteKey') || ! request()->isMethod('GET')
            || ! $route?->hasParameter('record') || ! $route->getName()) {
            return;
        }

        $key = $resource::personRouteKey($this->getRecord());
        if ((string) $route->parameter('record') !== $key) {
            $this->redirect(route($route->getName(), [
                ...request()->query(), ...$route->parameters(), 'record' => $key,
            ]));
        }
    }
}
