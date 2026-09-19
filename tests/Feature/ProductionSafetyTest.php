<?php

use App\Console\Commands\ResetTestData;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('production reset refuses before confirmation or any database work', function () {
    $patient = Patient::create(['first_name' => 'Production', 'last_name' => 'Preserved']);
    $owner = User::factory()->create();
    $this->app->instance('env', 'production');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->artisan('renome:reset-test-data')->expectsOutputToContain('disabled in production')
        ->assertExitCode(1);
    expect($queries)->toBeEmpty();
    expect($patient->fresh())->not->toBeNull()->and($owner->fresh()->role)->toBe(User::ROLE_OWNER);
    $command = app(ResetTestData::class);
    expect($command->getDefinition()->hasOption('force'))->toBeFalse();
});

test('non production reset remains available with confirmation', function (string $environment) {
    $this->app->instance('env', $environment);
    Patient::create(['first_name' => 'Disposable', 'last_name' => 'Fixture']);
    $this->artisan('renome:reset-test-data')
        ->expectsConfirmation('This will permanently delete all operational/test data. Continue?', 'yes')
        ->expectsOutputToContain('Operational/test data reset completed')->assertSuccessful();
    expect(Patient::count())->toBe(0);
})->with(['local', 'testing', 'development']);

test('production and staging seeding preserve users and only seed reference data', function (string $environment) {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $before = $owner->fresh()->getAttributes();
    $this->app->instance('env', $environment);
    app(DatabaseSeeder::class)->__invoke();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse()
        ->and(User::count())->toBe(1)->and($owner->fresh()->getAttributes())->toBe($before)
        ->and(PatientGroup::where('slug', PatientGroup::CLINIC_SLUG)->exists())->toBeTrue()
        ->and(PatientGroup::where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->exists())->toBeTrue();
})->with(['production', 'staging']);

test('demo owner is explicitly limited to local and testing seeders', function (string $environment) {
    $this->app->instance('env', $environment);
    app(DatabaseSeeder::class)->__invoke();
    expect(User::where('email', 'test@example.com')->sole()->role)->toBe(User::ROLE_OWNER);
})->with(['local', 'testing']);

test('new Eloquent users require an explicit recognized role', function (array $role) {
    expect(fn () => User::create([
        'name' => 'Rejected', 'email' => 'rejected@example.test', 'password' => 'not-a-default-password', ...$role,
    ]))->toThrow(ValidationException::class);
    expect(User::count())->toBe(0);
})->with([[[]], [['role' => null]], [['role' => '']], [['role' => 'unknown']], [['role' => 'Owner']]]);

test('all recognized explicit roles can be created', function (string $role) {
    $user = User::create(['name' => 'Explicit', 'email' => 'explicit@example.test', 'password' => 'not-a-default-password', 'role' => $role]);
    expect($user->fresh()->role)->toBe($role);
})->with(User::ROLES);

test('database inserts cannot implicitly create owners even without model events', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'No role', 'email' => 'no-role@example.test', 'password' => 'irrelevant-test-hash',
    ]))->toThrow(QueryException::class);
    expect(User::count())->toBe(0);
});

test('role default migration is reversible without changing existing users', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $before = DB::table('users')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_09_19_110000_remove_privileged_user_role_default.php');
    $roleColumn = fn () => collect(Schema::getColumns('users'))->firstWhere('name', 'role');
    expect($roleColumn()['default'])->toBeNull()->and($roleColumn()['nullable'])->toBeFalse();
    try {
        $migration->down();
        expect($roleColumn()['default'])->toContain('owner');
    } finally {
        $migration->up();
    }
    expect($roleColumn()['default'])->toBeNull()
        ->and(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before)
        ->and($owner->fresh()->role)->toBe(User::ROLE_OWNER)->and($admin->fresh()->role)->toBe(User::ROLE_ADMINISTRATOR);
});

test('Filament user creation rejects missing and forged unrecognized roles', function ($role) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(CreateUser::class)->fillForm([
        'name' => 'Rejected', 'email' => 'forged@example.test', 'password' => 'test-password', 'role' => $role,
    ])->call('create')->assertHasFormErrors(['role']);
    expect(User::where('email', 'forged@example.test')->exists())->toBeFalse();
})->with([null, 'unknown']);

test('historical unknown roles remain denied by authorization', function () {
    // Simulate a pre-existing invalid record, deliberately bypassing new creation validation.
    $user = User::factory()->createQuietly(['role' => 'unknown']);
    expect($user->canAccessPanel(filament()->getPanel('admin')))->toBeFalse()
        ->and($user->canManageOwnerModules())->toBeFalse()
        ->and($user->canManageClinicOperations())->toBeFalse()->and($user->canAccessLab())->toBeFalse();
});
