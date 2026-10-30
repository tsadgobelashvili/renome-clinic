<?php

use App\Http\Middleware\SecurityResponseHeaders;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\DatabaseSafety;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('ERP login includes noindex headers and metadata without affecting the public website host', function () {
    $this->get('https://erp.renome.ge/login')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'same-origin')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    foreach (['https://renome.ge/login', 'https://www.renome.ge/login', 'http://localhost/login'] as $url) {
        $this->get($url)->assertOk()->assertHeaderMissing('X-Robots-Tag')
            ->assertDontSee('<meta name="robots"', false);
    }
});

test('Laravel rendered errors have noindex and no store even after middleware unwinds', function (string $path) {
    $this->get('https://erp.renome.ge'.$path)->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
})->with(['/not-a-real-security-route', '/sitemap.xml']);

test('Laravel forbidden CSRF and server errors receive noindex and private no store', function (int $status) {
    Route::get('/synthetic-security-error', fn () => abort($status));
    $this->get('https://erp.renome.ge/synthetic-security-error')->assertStatus($status)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
})->with([403, 419, 500]);

test('malformed Host headers cannot cause a second exception while decorating an error', function () {
    $request = Request::create('/');
    $request->headers->set('host', 'invalid host');
    $response = SecurityResponseHeaders::apply($request, response('', 400));
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('X-Robots-Tag'))->toBeFalse();
});

test('authenticated clinical laboratory and financial pages prohibit shared caching', function (string $role, string $path) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get('https://erp.renome.ge'.$path)->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Pragma', 'no-cache');
})->with([['owner', '/finance'], ['administrator', '/patients'], ['lab_technician', '/lab-cases']]);

test('sensitive response decoration overwrites download public caching and stale validators', function () {
    $this->actingAs(User::factory()->create());
    $response = response('synthetic confidential content', 200, ['Cache-Control' => 'public, max-age=3600',
        'ETag' => 'old', 'Last-Modified' => 'Wed, 01 Jan 2020 00:00:00 GMT', 'Content-Type' => 'application/pdf']);
    SecurityResponseHeaders::apply(Request::create('https://erp.renome.ge/missing'), $response);
    expect($response->headers->get('Cache-Control'))->toBe('max-age=3600, public');
    $request = Request::create('https://erp.renome.ge/patients/1/history/pdf');
    $request->setUserResolver(fn () => auth()->user());
    SecurityResponseHeaders::apply($request, $response);
    expect($response->headers->get('Cache-Control'))->toBe('max-age=0, must-revalidate, no-store, private')
        ->and($response->headers->has('ETag'))->toBeFalse()->and($response->headers->has('Last-Modified'))->toBeFalse()
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf');
});

test('password changed sessions cannot use confidential export routes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['password_hash_web' => 'invalidated-password-hash']);
    $this->get('https://erp.renome.ge/patients/1/history/pdf')->assertRedirect('/login');
    $this->assertGuest();
});

test('robots allow reading noindex and no sitemap file is published', function () {
    $robots = file_get_contents(public_path('robots.txt'));
    expect($robots)->not->toContain('Disallow: /')->not->toContain('Sitemap:');
    expect(is_file(public_path('sitemap.xml')))->toBeFalse();
});

test('production secure cookie defaults and explicit deployment overrides are respected', function (string $environment, mixed $override, bool $expected) {
    // No kernel, .env load, database, sessions or cached deployment configuration.
    $code = 'require '.var_export(base_path('vendor/autoload.php'), true).'; new Illuminate\\Foundation\\Application('.var_export(base_path(), true).');'
        .'echo json_encode((require '.var_export(config_path('session.php'), true).')["secure"]);';
    $process = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => $environment, 'SESSION_SECURE_COOKIE' => $override]);
    $process->mustRun();
    expect(json_decode($process->getOutput(), true))->toBe($expected);
})->with([['production', false, true], ['local', false, false], ['production', 'false', false], ['production', 'true', true]]);

test('HTTPS session cookies retain Secure HttpOnly and SameSite Lax attributes', function () {
    config(['session.secure' => true]);
    $response = $this->get('https://erp.renome.ge/login')->assertOk();
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull()->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');
});

class EnforcedSecurityCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

test('CSRF middleware rejects a missing token and accepts the matching session token', function () {
    $this->app->bind(PreventRequestForgery::class, EnforcedSecurityCsrf::class);
    Route::post('/synthetic-csrf', fn () => response('ok'))->middleware('web');
    $this->withSession(['_token' => 'synthetic-csrf-token']);
    $this->post('https://erp.renome.ge/synthetic-csrf')->assertStatus(419)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->post('https://erp.renome.ge/synthetic-csrf', ['_token' => 'synthetic-csrf-token'])->assertOk();
});

test('route inventory and an isolated route cache retain confidential middleware and login routes', function () {
    $relative = 'storage/framework/testing/security-routes-'.Str::uuid().'.php';
    $path = base_path($relative);
    $original = getenv('APP_ROUTES_CACHE');
    putenv('APP_ROUTES_CACHE='.$relative);
    $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $relative;
    try {
        $this->artisan('route:list', ['--json' => true])->assertSuccessful();
        $this->artisan('route:cache')->assertSuccessful();
        expect(is_file($path))->toBeTrue();
        require $path;
        $routes = app('router')->getRoutes();
        expect($routes->getByName('filament.admin.auth.login'))->not->toBeNull();
        foreach (['patients.history.pdf', 'patients.history.word', 'treatment-estimates.pdf', 'treatment-estimates.word', 'purchases.import-report'] as $name) {
            expect($routes->getByName($name)->gatherMiddleware())->toContain('auth', AuthenticateSession::class);
        }
        $fresh = require base_path('bootstrap/app.php');
        $request = Request::create('https://erp.renome.ge/login');
        $fresh->instance('request', $request);
        $kernel = $fresh->make(Kernel::class);
        $kernel->bootstrap();
        DatabaseSafety::assertInMemory($fresh['db']->connection()->getConfig());
        expect($fresh->routesAreCached())->toBeTrue();
        $response = $kernel->handle($request);
        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive')
            ->and($response->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
        $kernel->terminate($request, $response);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if ($original === false) {
            putenv('APP_ROUTES_CACHE');
            unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
        } else {
            putenv('APP_ROUTES_CACHE='.$original);
            $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $original;
        }
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }
});
