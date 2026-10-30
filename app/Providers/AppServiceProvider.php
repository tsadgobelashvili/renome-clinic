<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Services\BogBusinessApiService;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseAnalysisMapping;
use App\Services\PurchaseCatalogDeletion;
use App\Support\PanelPageAccess;
use App\Validation\BoundedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Native k-anonymous check: fail open on service failure, bounded to 3s.
        // No request is made on login or when an unchanged password is omitted.
        $this->app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new BoundedPasswordVerifier($app->make(Factory::class), 3));
        $this->app->scoped(ExpenseDimensions::class);
        $this->app->scoped(PurchaseAnalysisMapping::class);
        $this->app->scoped(PurchaseCatalogDeletion::class);
        $this->app->scoped(BogBusinessApiService::class);
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(15)->uncompromised());
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
