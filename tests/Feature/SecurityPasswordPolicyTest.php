<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function securityBreachResponse($response): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/range/*' => $response]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['password' => 'Legacy8!']));
    securityBreachResponse(Http::response(str_repeat('0', 35).':0', 200));
});

test('new user passwords require fifteen characters and strong passwords create accounts normally', function () {
    $page = Livewire::test(CreateUser::class)->fillForm(['name' => 'Synthetic employee', 'email' => 'new@example.test',
        'password' => 'Legacy8!', 'role' => 'lab_technician', 'locale' => 'ka', 'is_active' => true])
        ->call('create')->assertHasFormErrors(['password']);
    expect(User::where('email', 'new@example.test')->exists())->toBeFalse();
    $page->fillForm(['password' => 'Synthetic long passphrase 2026!'])->call('create')->assertHasNoFormErrors();
    expect(Hash::check('Synthetic long passphrase 2026!', User::where('email', 'new@example.test')->sole()->password))->toBeTrue();
});

test('editing an existing account without a password preserves its legacy hash and skips the breach service', function () {
    $user = User::factory()->create(['password' => 'Legacy8!']);
    $before = $user->getRawOriginal('password');
    Livewire::test(EditUser::class, ['record' => $user->id])->fillForm(['name' => 'Updated name', 'password' => ''])
        ->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->getRawOriginal('password'))->toBe($before);
    Http::assertNothingSent();
});

test('changed passwords use the same policy in account editing and the existing profile form', function (bool $profile) {
    $user = auth()->user();
    $before = $user->getRawOriginal('password');
    $page = $profile ? Livewire::test(EditProfile::class) : Livewire::test(EditUser::class, ['record' => $user->id]);
    $page->fillForm(['password' => 'short123', 'passwordConfirmation' => 'short123', 'currentPassword' => 'Legacy8!'])
        ->call('save')->assertHasFormErrors(['password']);
    expect($user->fresh()->getRawOriginal('password'))->toBe($before);
    $page->fillForm(['password' => 'Synthetic changed passphrase 2026!',
        'passwordConfirmation' => 'Synthetic changed passphrase 2026!', 'currentPassword' => 'Legacy8!'])
        ->call('save')->assertHasNoFormErrors();
    expect(Hash::check('Synthetic changed passphrase 2026!', $user->fresh()->password))->toBeTrue();
})->with([false, true]);

test('known compromised passwords are rejected and only a padded five character hash prefix is sent', function () {
    $password = 'Synthetic compromised passphrase';
    $hash = strtoupper(sha1($password));
    securityBreachResponse(Http::response(substr($hash, 5).':42', 200));
    $validator = Validator::make(['password' => $password], ['password' => [Password::default()]]);
    expect($validator->fails())->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
        && $request->hasHeader('Add-Padding') && $request->body() === '');
});

test('a breach service failure never blocks an otherwise valid new password', function (string $failure) {
    Log::spy();
    securityBreachResponse(match ($failure) {
        'connection' => Http::failedConnection(),
        'unavailable' => Http::response('', 503),
        default => Http::response('unavailable', 200),
    });
    expect(Validator::make(['password' => 'Synthetic available passphrase'], ['password' => [Password::default()]])->passes())->toBeTrue();
    expect(Validator::make(['password' => 'short123'], ['password' => [Password::default()]])->fails())->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with('Password breach service unavailable; local password rules remain enforced.');
})->with(['connection', 'unavailable', 'malformed']);

test('saving an unchanged profile does not validate or rewrite its legacy password', function () {
    $user = auth()->user();
    $hash = $user->getRawOriginal('password');
    Livewire::test(EditProfile::class)->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->getRawOriginal('password'))->toBe($hash);
    Http::assertNothingSent();
});

test('the native breach verifier remains bounded after deferred validation provider registration', function () {
    app('validator');
    $verifier = app(UncompromisedVerifier::class);
    expect((new ReflectionProperty($verifier, 'timeout'))->getValue($verifier))->toBe(3);
});
