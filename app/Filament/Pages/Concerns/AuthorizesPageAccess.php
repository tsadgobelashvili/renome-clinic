<?php

namespace App\Filament\Pages\Concerns;

use Illuminate\Support\Facades\Gate;

trait AuthorizesPageAccess
{
    public static function canAccess(): bool
    {
        return Gate::allows('access-panel-page', static::class);
    }
}
