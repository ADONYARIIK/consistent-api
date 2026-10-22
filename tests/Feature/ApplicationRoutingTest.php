<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\ConsistentApiProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A real application built like bootstrap/app.php builds it, with withRouting().
 *
 * Testbench does not route through withRouting(), so the order in which route files, macros and module
 * routes load is only visible in an application of its own.
 */
class ApplicationRoutingTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/consistent-app-'.bin2hex(random_bytes(4));

        foreach (['routes', 'app/Modules/Posts', 'bootstrap/cache', 'storage/framework'] as $directory) {
            File::ensureDirectoryExists($this->base.'/'.$directory);
        }

        $counter = "\$GLOBALS['consistent_route_loads'][basename(__FILE__)] = (\$GLOBALS['consistent_route_loads'][basename(__FILE__)] ?? 0) + 1;\n";

        File::put($this->base.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".$counter
            ."Route::development(fn () => Route::get('web-dev', fn () => 'dev'));\nRoute::get('web', fn () => 'web');\n");
        File::put($this->base.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".$counter
            ."Route::get('ping', fn () => 'pong');\n");
        File::put($this->base.'/app/Modules/Posts/Routes.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
            ."Route::development(fn () => Route::get('posts/dev', fn () => 'dev'));\nRoute::get('posts', fn () => 'posts')->name('posts.index');\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        unset($GLOBALS['consistent_route_loads']);

        parent::tearDown();
    }

    /**
     * Builds and bootstraps the application in the given environment, then puts the test application back.
     */
    private function bootApplication(string $environment): Application
    {
        $variables = ['APP_ENV' => getenv('APP_ENV')];
        $this->setEnvironmentVariable('APP_ENV', $environment);

        try {
            $app = Application::configure($this->base)
                ->withRouting(web: $this->base.'/routes/web.php', api: $this->base.'/routes/api.php')
                ->withProviders([ConsistentApiProvider::class])
                ->withExceptions()
                ->create();

            $app->make(Kernel::class)->bootstrap();

            return $app;
        } finally {
            foreach ($variables as $name => $value) {
                $this->setEnvironmentVariable($name, $value === false ? null : $value);
            }

            RouteServiceProvider::loadRoutesUsing(null);
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }
    }

    private function setEnvironmentVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    /**
     * @return list<string>
     */
    private function uris(Application $app): array
    {
        return array_map(fn ($route) => $route->uri(), $app['router']->getRoutes()->getRoutes());
    }

    public function test_route_files_load_once_and_can_use_the_development_macro(): void
    {
        foreach (['production', 'local'] as $environment) {
            unset($GLOBALS['consistent_route_loads']);

            $app = $this->bootApplication($environment);

            $this->assertEquals(['web.php' => 1, 'api.php' => 1], $GLOBALS['consistent_route_loads'], $environment);

            $uris = $this->uris($app);
            $this->assertContains('web', $uris);
            $this->assertContains('api/ping', $uris);
            $this->assertContains('api/posts', $uris);
            $this->assertSame(1, count(array_keys($uris, 'web', true)));
            $this->assertNotNull($app['router']->getRoutes()->getByName('posts.index'));

            $this->assertSame($environment === 'local', in_array('web-dev', $uris, true), $environment);
            $this->assertSame($environment === 'local', in_array('api/posts/dev', $uris, true), $environment);
        }
    }
}
