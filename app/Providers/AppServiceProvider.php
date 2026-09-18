<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Support\PanelPageAccess;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ExpenseDimensions::class);
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-panel-page', fn (User $user, string $page): bool => PanelPageAccess::allows($user, $page));

        if (PHP_OS_FAMILY === 'Windows' && $this->app->runningInConsole()) {
            // `artisan serve` strips non-allowlisted environment variables on reload.
            // PHP needs these before request startup to create multipart upload files.
            ServeCommand::$passthroughVariables = array_values(array_unique([
                ...ServeCommand::$passthroughVariables, 'TEMP', 'TMP', 'TMPDIR',
            ]));
        }
    }
}
