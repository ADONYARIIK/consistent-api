<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Runs its tests on one database driver: in-memory SQLite, or a PostgreSQL / MySQL server configured with
 * TEST_PGSQL_* / TEST_MYSQL_* variables.
 *
 * Without TEST_{DRIVER}_HOST the server tests are skipped. With it, a server that cannot be used (missing
 * PDO extension, refused connection, wrong credentials or database) fails the first test of the driver with
 * the reason, and the remaining tests of that driver are skipped instead of repeating the same error.
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * Why the server of a driver cannot be used, once the first test found out.
     *
     * @var array<string, string>
     */
    private static array $unusable = [];

    abstract protected static function driver(): string;

    protected function setUp(): void
    {
        $driver = static::driver();

        if ($driver !== 'sqlite') {
            $this->requireServer($driver);
        }

        parent::setUp();

        $this->dropEverything();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app !== null) {
                $this->dropEverything();
            }
        } finally {
            // Always tear the application down, or its error handlers outlive the test
            parent::tearDown();
        }
    }

    protected function defineEnvironment($app): void
    {
        $driver = static::driver();

        if ($driver === 'sqlite') {
            return;
        }

        $app['config']->set('database.connections.'.$driver, array_merge(
            (array) $app['config']->get('database.connections.'.$driver, []),
            (array) static::serverConnection($driver),
        ));
        $app['config']->set('database.default', $driver);
    }

    /**
     * Skips the test without a configured server and fails it, once per driver, when the server is unusable.
     */
    private function requireServer(string $driver): void
    {
        $connection = static::serverConnection($driver);
        $variable = 'TEST_'.strtoupper($driver).'_HOST';

        if ($connection === null) {
            $this->markTestSkipped(sprintf('Set %s to run the %s tests.', $variable, $driver));
        }

        if (isset(self::$unusable[$driver])) {
            $this->markTestSkipped(sprintf('The %s server cannot be used, see the first failed %s test: %s', $driver, $driver, self::$unusable[$driver]));
        }

        $problem = $this->serverProblem($connection);

        if ($problem !== null) {
            self::$unusable[$driver] = $problem;

            $this->fail(sprintf(
                "%s is set, but the %s tests cannot run: %s\nStart the server (README, Development), fix the TEST_%s_* variables, or unset %s to skip these tests.",
                $variable,
                $driver,
                $problem,
                strtoupper($driver),
                $variable,
            ));
        }
    }

    /**
     * What keeps the configured server from being used, or null when a connection succeeds.
     *
     * @param  array<string, mixed>  $connection  Settings from serverConnection()
     */
    private function serverProblem(array $connection): ?string
    {
        if (! extension_loaded('pdo_'.$connection['driver'])) {
            return sprintf('the PHP extension pdo_%s is not installed.', $connection['driver']);
        }

        try {
            new PDO(
                sprintf('%s:host=%s;port=%s;dbname=%s', $connection['driver'], $connection['host'], $connection['port'], $connection['database']),
                (string) $connection['username'],
                (string) $connection['password'],
                [PDO::ATTR_TIMEOUT => 3],
            );
        } catch (PDOException $exception) {
            return sprintf('%s:%s (database %s) answered "%s".', $connection['host'], $connection['port'], $connection['database'], $exception->getMessage());
        }

        return null;
    }

    /**
     * Server databases outlive the test, so every test starts and ends with an empty schema.
     */
    private function dropEverything(): void
    {
        match (static::driver()) {
            'pgsql' => DB::unprepared('drop schema public cascade; create schema public;'),
            'mysql' => Schema::dropAllTables(),
            default => null,
        };
    }
}
