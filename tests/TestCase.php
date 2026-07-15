<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fail closed: refuse to run the suite against anything but a test database.
     *
     * Runs before the application boots (and therefore before RefreshDatabase
     * could migrate/truncate), reading the database name straight from the
     * environment that PHPUnit configured. The name must contain "test", which
     * a live database ("portal-live", "brookstone_portal_db") never does.
     */
    protected function setUp(): void
    {
        $database = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;

        if (! is_string($database) || preg_match('/test/i', $database) !== 1) {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests against database [%s]: the test database name must contain "test".',
                var_export($database, true),
            ));
        }

        parent::setUp();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
