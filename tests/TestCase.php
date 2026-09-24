<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Refuse cached deployment credentials before RefreshDatabase can migrate.
        if ($app->configurationIsCached()) {
            throw new \RuntimeException('Tests cannot use cached application configuration. Set APP_CONFIG_CACHE to a separate nonexistent path before running tests.');
        }

        // Resolve configuration only: getConfig() does not open a PDO connection.
        $names = array_merge(
            [$app['config']->get('database.default')],
            method_exists($this, 'connectionsToTransact') ? $this->connectionsToTransact() : [],
        );
        foreach (array_unique($names) as $name) {
            DatabaseSafety::assertInMemory($app['db']->connection($name)->getConfig());
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral test-only secret; never use or change the local deployment key.
        config(['patient_identifiers.hash_key' => random_bytes(32)]);
    }
}
