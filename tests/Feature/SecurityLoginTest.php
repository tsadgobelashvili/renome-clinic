<?php

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('legacy eight character credentials still sign in without MFA or password changes', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'password' => 'Legacy8!']);
    $before = $user->fresh()->getRawOriginal();
    expect(Filament::getPanel('admin')->getLoginRouteAction())->toBe(Login::class)
        ->and(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeFalse();
    $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Legacy8!', 'remember' => true]);
    $session = session()->getId();
    $login->call('authenticate')->assertHasNoFormErrors()->assertRedirect($role === 'lab_technician' ? url('/lab-cases') : url('/'))
        ->assertSet('userUndertakingMultiFactorAuthentication', null);
    $this->assertAuthenticatedAs($user);
    $this->assertNotEquals($session, session()->getId());
    foreach (['email', 'password', 'name', 'role', 'is_active'] as $field) {
        expect($user->fresh()->getRawOriginal($field))->toBe($before[$field]);
    }
    expect($user->fresh()->remember_token)->not->toBeNull();
    Http::assertNothingSent();
    $recaller = auth()->guard()->getRecallerName();
    $cookie = collect(Cookie::getQueuedCookies())->first(fn ($cookie) => $cookie->getName() === $recaller);
    expect($cookie)->not->toBeNull();
    session()->flush();
    app('auth')->forgetGuards();
    $this->withCookie($recaller, $cookie->getValue());
    $this->get($role === 'lab_technician' ? '/lab-cases' : '/')->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(auth()->guard()->viaRemember())->toBeTrue();
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
})->with(['owner', 'administrator', 'lab_technician']);

test('inactive and unknown accounts retain the same generic authentication rejection', function (string $role, bool $active) {
    $user = User::factory()->createQuietly(['role' => $role, 'is_active' => $active, 'password' => 'Legacy8!']);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Legacy8!'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
})->with([['owner', false], ['administrator', false], ['lab_technician', false], ['unknown', true]]);

test('invalid passwords are rejected without changing the legacy credential', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'password' => 'Legacy8!']);
    $hash = $user->getRawOriginal('password');
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'incorrect'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
    expect($user->fresh()->getRawOriginal('password'))->toBe($hash);
    Http::assertNothingSent();
})->with(['owner', 'administrator', 'lab_technician']);

/** Exercise the real login method under a different network address each time. */
function securityLoginFromIp(string $email, string $password, string $ip): ?ValidationException
{
    $login = Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->instance();
    $original = request();
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $request->setLaravelSession(session()->driver());
    app()->instance('request', $request);
    try {
        $login->authenticate();

        return null;
    } catch (ValidationException $exception) {
        return $exception;
    } finally {
        app()->instance('request', $original);
    }
}

test('distributed failures trigger a temporary account cooldown which expires', function (bool $existing) {
    $this->freezeTime();
    $email = $existing ? User::factory()->create(['password' => 'Legacy8!'])->email : 'unknown@example.test';
    $message = null;
    for ($attempt = 1; $attempt <= Login::ACCOUNT_ATTEMPTS; $attempt++) {
        $failure = securityLoginFromIp($email, 'incorrect', '192.0.2.'.$attempt);
        expect($failure)->toBeInstanceOf(ValidationException::class);
        $message ??= $failure->errors();
        expect($failure->errors())->toBe($message);
    }
    $this->travel(Login::ACCOUNT_COOLDOWN_SECONDS - 1)->seconds();
    $failure = securityLoginFromIp($email, 'Legacy8!', '192.0.2.100');
    expect($failure->errors())->toBe($message);
    $this->assertGuest();
    // Blocked requests must not extend the cooldown indefinitely.
    $this->travel(2)->seconds();
    $failure = securityLoginFromIp($email, 'Legacy8!', '192.0.2.101');
    if ($existing) {
        expect($failure)->toBeNull();
        $this->assertAuthenticated();
    } else {
        expect($failure->errors())->toBe($message);
        $this->assertGuest();
    }
})->with([true, false]);

test('a normal successful login clears prior account failures and does not lock other accounts', function () {
    $user = User::factory()->create(['password' => 'Legacy8!']);
    for ($attempt = 1; $attempt < Login::ACCOUNT_ATTEMPTS; $attempt++) {
        securityLoginFromIp($user->email, 'incorrect', '192.0.2.'.$attempt);
    }
    expect(securityLoginFromIp($user->email, 'Legacy8!', '192.0.2.90'))->toBeNull();
    auth()->logout();
    for ($attempt = 1; $attempt < Login::ACCOUNT_ATTEMPTS; $attempt++) {
        securityLoginFromIp($user->email, 'incorrect', '198.51.100.'.$attempt);
    }
    expect(securityLoginFromIp($user->email, 'Legacy8!', '198.51.100.90'))->toBeNull();
});

test('the existing five per minute IP throttle is retained across different accounts', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        Livewire::test(Login::class)->fillForm(['email' => 'unknown'.$attempt.'@example.test', 'password' => 'incorrect'])
            ->call('authenticate')->assertHasFormErrors(['email']);
    }
    $user = User::factory()->create(['password' => 'Legacy8!']);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Legacy8!'])
        ->call('authenticate')->assertNotified();
    $this->assertGuest();
    $this->travel(61)->seconds();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Legacy8!'])
        ->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
});

test('account keys normalize email variants without changing stored login identifiers', function () {
    $user = User::factory()->create(['email' => 'Mixed@example.test', 'password' => 'Legacy8!']);
    for ($attempt = 1; $attempt <= Login::ACCOUNT_ATTEMPTS; $attempt++) {
        securityLoginFromIp($attempt % 2 ? 'mixed@example.test' : 'MIXED@example.test', 'incorrect', '192.0.2.'.$attempt);
    }
    expect(securityLoginFromIp('Mixed@example.test', 'Legacy8!', '192.0.2.99'))->toBeInstanceOf(ValidationException::class);
    expect($user->fresh()->email)->toBe('Mixed@example.test');
    $this->assertGuest();
});
