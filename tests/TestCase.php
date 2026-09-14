<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral test-only secret; never use or change the local deployment key.
        config(['patient_identifiers.hash_key' => random_bytes(32)]);
    }
}
