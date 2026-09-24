<?php

namespace Tests;

final class DatabaseSafety
{
    public static function assertInMemory(array $connection): void
    {
        if (($connection['driver'] ?? null) !== 'sqlite'
            || ($connection['database'] ?? null) !== ':memory:'
            || ! empty($connection['url'])
            || ! empty($connection['read'])
            || ! empty($connection['write'])) {
            throw new \RuntimeException('Database tests may use only isolated SQLite :memory: connections. No database refresh is permitted.');
        }
    }
}
