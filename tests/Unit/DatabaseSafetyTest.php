<?php

use Tests\DatabaseSafety;

test('database safety permits only SQLite memory connections', function () {
    DatabaseSafety::assertInMemory(['driver' => 'sqlite', 'database' => ':memory:', 'url' => null]);
    expect(true)->toBeTrue();
});

test('database safety rejects persistent and overridden connections', function (array $connection) {
    expect(fn () => DatabaseSafety::assertInMemory($connection))->toThrow(RuntimeException::class);
})->with([
    'local PostgreSQL' => [['driver' => 'pgsql', 'database' => 'renome_clinic']],
    'misleading memory name' => [['driver' => 'pgsql', 'database' => ':memory:']],
    'SQLite file' => [['driver' => 'sqlite', 'database' => 'database/database.sqlite']],
    'URL override' => [['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///persistent.sqlite']],
    'read override' => [['driver' => 'sqlite', 'database' => ':memory:', 'read' => ['database' => 'persistent.sqlite']]],
    'write override' => [['driver' => 'sqlite', 'database' => ':memory:', 'write' => ['database' => 'persistent.sqlite']]],
    'missing configuration' => [[]],
]);
