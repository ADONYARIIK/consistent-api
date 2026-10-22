<?php

declare(strict_types=1);

namespace Tests;

use Adonyarik\ConsistentApi\ConsistentApiProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Fixtures\PassThroughMiddleware;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ConsistentApiProvider::class];
    }

    /**
     * Points middleware aliases at PassThroughMiddleware. The HTTP kernel copies its own aliases
     * into the router when it is built, so it is built first.
     */
    protected function stubMiddlewareAliases(string ...$aliases): void
    {
        $this->app->make(HttpKernel::class);

        foreach ($aliases as $alias) {
            $this->app['router']->aliasMiddleware($alias, PassThroughMiddleware::class);
        }
    }

    /**
     * Expects the exception message to contain $message.
     *
     * expectExceptionMessage() is deprecated since PHPUnit 13.2 (https://github.com/sebastianbergmann/phpunit/issues/6560),
     * while its replacement is missing in PHPUnit 12.5, the version PHP 8.3 runs (PHPUnit 13 needs PHP 8.4).
     */
    protected function expectExceptionMessageToContain(string $message): void
    {
        if (method_exists($this, 'expectExceptionMessageIsOrContains')) {
            $this->expectExceptionMessageIsOrContains($message);

            return;
        }

        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');
    }

    /**
     * Connection settings of a database server from TEST_PGSQL_* / TEST_MYSQL_* variables.
     *
     * Null when TEST_{DRIVER}_HOST is not set, so the tests that need the server are skipped. Whether the
     * PDO driver is installed and the server answers is checked by DatabaseTestCase.
     *
     * @return array<string, mixed>|null
     */
    protected static function serverConnection(string $driver): ?array
    {
        $prefix = 'TEST_'.strtoupper($driver).'_';
        $host = getenv($prefix.'HOST');

        if ($host === false || $host === '') {
            return null;
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => getenv($prefix.'PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv($prefix.'DATABASE') ?: 'consistent',
            'username' => getenv($prefix.'USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv($prefix.'PASSWORD') ?: '',
        ];
    }
}
