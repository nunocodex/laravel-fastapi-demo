<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Run migrations on the in-memory SQLite database before each test.
        $this->artisan('migrate', ['--force' => true]);
    }
}
