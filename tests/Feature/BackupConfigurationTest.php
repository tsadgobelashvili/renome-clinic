<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\Backup\Tasks\Backup\FileSelection;
use Spatie\Backup\Tasks\Backup\Zip;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;

// No RefreshDatabase: these tests must never migrate, dump or query an application DB.
test('backup package configuration and commands are valid without contacting Spaces', function () {
    $config = Config::fromArray(config('backup'));

    expect($config->backup->source->databases)->toBe(['pgsql'])
        ->and($config->backup->destination->disks)->toBe(['s3'])
        ->and($config->backup->verifyBackup)->toBeTrue()
        ->and($config->cleanup->defaultStrategy->deleteOldestBackupsWhenUsingMoreMegabytesThan)->toBeNull()
        ->and(config('filesystems.disks.s3.backup_options.visibility'))->toBe('private');

    foreach (['backup:run', 'backup:clean', 'backup:list', 'backup:monitor'] as $command) {
        expect(Artisan::all())->toHaveKey($command);
    }
});

test('PostgreSQL backup uses a portable full custom dump without exposing the password', function () {
    config(['database.connections.pgsql.url' => null,
        'database.connections.pgsql.database' => 'backup_fixture',
        'database.connections.pgsql.username' => 'fixture_user',
        'database.connections.pgsql.password' => 'fixture-secret-not-real']);
    $command = DbDumperFactory::createFromConnection('pgsql')->getDumpCommand('fixture.dump');

    expect($command)->toContain('pg_dump', '--format=custom', '--no-owner', '--no-acl')
        ->not->toContain('fixture-secret-not-real', '--data-only', '--schema-only', '--clean');
});

test('file selection includes uploads but excludes rebuildable and temporary data', function () {
    $originalStorage = storage_path();
    $root = storage_path('framework/testing/backup-selection-'.bin2hex(random_bytes(6)));
    $this->app->useStoragePath($root);
    try {
        $files = [
            'app/private/documents/patient.pdf', 'app/public/uploads/photo.jpg',
            'app/private/livewire-tmp/upload.tmp', 'app/public/livewire-tmp/upload.tmp',
            'framework/cache/cache.txt', 'logs/laravel.log', 'app/backup-temp/dump.sql',
        ];
        foreach ($files as $file) {
            File::ensureDirectoryExists(dirname($root.'/'.$file));
            File::put($root.'/'.$file, 'synthetic fixture');
        }
        $config = require base_path('config/backup.php');
        $selection = FileSelection::create($config['backup']['source']['files']['include'])
            ->excludeFilesFrom($config['backup']['source']['files']['exclude']);
        $selected = collect(iterator_to_array($selection->selectedFiles()))
            ->filter(fn ($path) => is_file($path))
            ->map(fn ($path) => str_replace('\\', '/', substr($path, strlen($root) + 1)))
            ->sort()->values()->all();
        expect($selected)->toBe(['app/private/documents/patient.pdf', 'app/public/uploads/photo.jpg']);
    } finally {
        $this->app->useStoragePath($originalStorage);
        File::deleteDirectory($root);
    }
});

test('scheduled backups are production opt-in with overlap protection and app timezone', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'backup:'))->values();
    expect($events)->toHaveCount(3);
    foreach (['backup:clean' => '30 2 * * *', 'backup:run --isolated=1' => '0 3 * * *', 'backup:monitor' => '0 4 * * *'] as $command => $expression) {
        $event = $events->first(fn ($event) => str_contains($event->command, $command));
        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($expression)
            ->and($event->timezone)->toBe(config('app.timezone'))
            ->and($event->environments)->toBe(['production'])
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runsInEnvironment('testing'))->toBeFalse();
        config(['backup.enabled' => false]);
        expect($event->filtersPass($this->app))->toBeFalse();
        config(['backup.enabled' => true]);
        expect($event->filtersPass($this->app))->toBeTrue();
    }
});

test('retention deletes only expired fixture archives and keeps newest and recent backups', function () {
    $this->travelTo(now()->startOfDay());
    $disk = Storage::fake('s3');
    $prefix = config('backup.backup.name');
    $paths = [];
    foreach ([0, 1, 5, 1000] as $age) {
        $path = $prefix.'/'.now()->subDays($age)->format('Y-m-d-H-i-s').'.zip';
        $disk->put($path, 'synthetic archive');
        $paths[] = $path;
    }
    $other = 'another-application/old.zip';
    $disk->put($other, 'not ours');
    $backups = new BackupCollection(array_map(fn ($path) => new Backup($disk, $path), $paths));
    (new DefaultStrategy(Config::fromArray(config('backup'))))->deleteOldBackups($backups);
    $disk->assertExists([$paths[0], $paths[1], $paths[2], $other]);
    $disk->assertMissing($paths[3]);
});

test('encrypted archive can be read and sent privately to a fake backup destination', function () {
    $disk = Storage::fake('s3');
    $root = storage_path('framework/testing/backup-archive-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($root);
    config(['backup.backup.password' => 'synthetic-test-password']);
    Config::rebind();
    try {
        File::put($root.'/upload.txt', 'synthetic upload');
        $zip = new Zip($root.'/fixture.zip');
        $zip->add($root.'/upload.txt', 'app/private/upload.txt');
        $zip->close();
        $reader = new ZipArchive;
        expect($reader->open($root.'/fixture.zip'))->toBeTrue();
        expect($reader->statName('app/private/upload.txt')['encryption_method'])->toBe(ZipArchive::EM_AES_256);
        $reader->setPassword('synthetic-test-password');
        expect($reader->getFromName('app/private/upload.txt'))->toBe('synthetic upload');
        $reader->close();
        $destination = BackupDestination::create('s3', 'fixture-backups');
        // Windows local fake disks cannot report POSIX private permissions reliably.
        // Check the actual options passed to the S3 driver's writeStream instead.
        expect($destination->getDiskOptions())->toBe(['visibility' => 'private']);
        $destination->write($root.'/fixture.zip');
        $disk->assertExists('fixture-backups/fixture.zip');
    } finally {
        File::deleteDirectory($root);
    }
});
