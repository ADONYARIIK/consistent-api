<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Providers\ModuleServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ModuleServiceProvider: module route files and the api rate limiter.
 */
class ModuleRoutesTest extends TestCase
{
    private const ROUTE_FILE = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('%s', fn () => '%s')->name('%s');\n";

    /**
     * The route files must exist before the providers boot.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.modules_folder', 'Domain/Modules');
        $app['config']->set('consistentapi.api_url_prefix', 'v1');
        $app['config']->set('consistentapi.request_limit', 5);

        $modules = $app->path('Domain/Modules');

        File::ensureDirectoryExists($modules.'/Zeta');
        File::ensureDirectoryExists($modules.'/Alpha');
        File::put($modules.'/Zeta/Routes.php', sprintf(self::ROUTE_FILE, 'zeta', 'zeta', 'module.zeta'));
        File::put($modules.'/Alpha/Routes.php', sprintf(self::ROUTE_FILE, 'alpha', 'alpha', 'module.alpha'));
        File::ensureDirectoryExists($modules.'/Books');
        File::ensureDirectoryExists($modules.'/Middleware');
        File::ensureDirectoryExists($modules.'/Empty');
        File::put($modules.'/Books/Routes.php', sprintf(self::ROUTE_FILE, 'books', 'books', 'module.books'));
        File::put($modules.'/Middleware/Routes.php', sprintf(self::ROUTE_FILE, 'hidden', 'hidden', 'module.hidden'));
        File::put($modules.'/Routes.php', sprintf(self::ROUTE_FILE, 'global', 'global', 'module.global'));
        File::put($modules.'/README.md', 'not a module');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Domain'));

        parent::tearDown();
    }

    public function test_loads_module_and_global_route_files_with_prefix_and_middleware(): void
    {
        $this->get('/v1/books')->assertOk()->assertSee('books');
        $this->get('/v1/global')->assertOk()->assertSee('global');
        $this->get('/books')->assertNotFound();

        $this->assertSame(['api'], Route::getRoutes()->getByName('module.books')->middleware());
        $this->assertSame(['api'], Route::getRoutes()->getByName('module.global')->middleware());
    }

    public function test_registers_modules_in_name_order_whatever_the_file_system_order(): void
    {
        $uris = array_values(array_filter(
            array_map(fn ($route) => $route->uri(), Route::getRoutes()->getRoutes()),
            fn (string $uri) => str_starts_with($uri, 'v1/'),
        ));

        $this->assertSame(['v1/alpha', 'v1/books', 'v1/zeta', 'v1/global'], $uris);
    }

    public function test_rejects_a_modules_folder_outside_app(): void
    {
        config(['consistentapi.modules_folder' => '../Outside']);
        $load = new ReflectionMethod(ModuleServiceProvider::class, 'loadModuleRoutes');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageToContain('every segment must be a PHP identifier');

        $load->invoke(new ModuleServiceProvider($this->app));
    }

    public function test_skips_the_middleware_folder(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('module.hidden'));
        $this->get('/v1/hidden')->assertNotFound();
    }

    public function test_api_limiter_uses_the_configured_limit_per_user_or_ip(): void
    {
        $limiter = RateLimiter::limiter('api');

        $guest = Request::create('/v1/books', server: ['REMOTE_ADDR' => '10.0.0.7']);
        $limit = $limiter($guest);

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame('10.0.0.7', $limit->key);

        $user = Request::create('/v1/books');
        $user->setUserResolver(fn () => (object) ['id' => 42]);

        $this->assertSame(42, $limiter($user)->key);
    }
}
