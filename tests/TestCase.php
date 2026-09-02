<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fail closed: refuse to run the suite against anything but a test database.
     *
     * Runs before RefreshDatabase could migrate/truncate. The name must contain
     * "test", which a live database ("portal-live", "brookstone_portal_db")
     * never does.
     *
     * IT NOW READS THE CONNECTION, NOT THE ENVIRONMENT, AND THAT CHANGE IS THE
     * WHOLE POINT. The previous version read $_SERVER/$_ENV/getenv('DB_DATABASE')
     * — a value THE CONNECTION DOES NOT USE — so it could not fire in the one
     * case it exists for. On 2026-09-02 a stale bootstrap/cache/config.php made
     * every <env> in phpunit.xml inert (a cached config never calls env()), the
     * app opened the LOCAL database while phpunit's DB_DATABASE still said
     * "portal_testing", this guard read that env var, passed, and RefreshDatabase
     * dropped and rebuilt all 100 tables of the production copy. The guard was
     * green throughout. Reading the same value the connection was built from is
     * the only version that can refuse.
     *
     * WHY refreshApplication() AND NOT setUp(). The old check ran before
     * parent::setUp(), i.e. before the container existed — DB::connection() there
     * would throw "A facade root has not been set". Illuminate's
     * setUpTheTestEnvironment() calls refreshApplication() and THEN setUpTraits(),
     * and setUpTraits() is where RefreshDatabase migrates. Overriding
     * refreshApplication() therefore runs after the app is bootable and before
     * anything touches the schema, which is exactly the window this guard needs.
     *
     * getDatabaseName() OPENS NO CONNECTION: Connection::getDatabaseName() returns
     * the string the connection was configured with, and the PDO is a lazy closure
     * resolved on first query (ConnectionFactory::createPdoResolverWithoutHosts).
     * So a wrong or unreachable host still refuses here rather than hanging.
     *
     * RESIDUAL, STATED BECAUSE A GUARD THAT READS STRONGER THAN IT IS, IS HOW
     * TODAY HAPPENED: this matches on the database NAME and nothing else. A
     * connection to a PRODUCTION HOST holding a database whose name contains
     * "test" passes it, and so does a test-named database on a production server
     * reached through the wrong credentials. It bounds the blast radius by naming
     * convention, not by proving the target is disposable. Host, port and user are
     * unchecked, deliberately: they vary per developer and per CI, and a guard
     * that fails on a legitimate local setup gets bypassed, which is worse than a
     * narrower guard people keep.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $database = DB::connection()->getDatabaseName();

        if (! is_string($database) || preg_match('/test/i', $database) !== 1) {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests against database [%s]: the test database name must contain "test". '
                    .'This is the CONNECTION\'s database, not $_SERVER[\'DB_DATABASE\'] — if they disagree, '
                    .'a compiled config cache is overriding phpunit.xml (delete bootstrap/cache/config.php).',
                var_export($database, true),
            ));
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
