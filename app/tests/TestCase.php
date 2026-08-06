<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suffix every database the suite is allowed to touch must carry.
     */
    private const TESTING_DATABASE_SUFFIX = '_testing';

    /**
     * Guard the database name as soon as the application exists.
     *
     * refreshApplication() is what parent::setUp() calls to build $this->app,
     * and it runs before setUpTraits() processes RefreshDatabase. Checking
     * here — rather than after parent::setUp() returns — means the guard is
     * evaluated before RefreshDatabase can drop a single table, instead of
     * after the destructive work it exists to prevent is already done.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->ensureTestingDatabase();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Refuse to run against anything but a disposable testing database.
     *
     * RefreshDatabase drops every table, so a leaked DB_DATABASE (for example
     * from a deployed container's environment) would destroy real data. This is
     * the last line of defence behind the forced <env> values in phpunit.xml.
     */
    private function ensureTestingDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! str_ends_with($database, self::TESTING_DATABASE_SUFFIX)) {
            throw new RuntimeException(
                "Refusing to run the test suite against database [{$database}]: "
                .'its name must end in ['.self::TESTING_DATABASE_SUFFIX.'].',
            );
        }
    }
}
