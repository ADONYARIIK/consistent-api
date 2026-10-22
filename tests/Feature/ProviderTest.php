<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\ConsistentApiProvider;
use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tests\Concerns\CreatesLibrary;
use Tests\Fixtures\Book;
use Tests\Fixtures\PassThroughMiddleware;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use CreatesLibrary;

    public function test_merges_the_package_config(): void
    {
        $this->assertSame('Modules', config('consistentapi.modules_folder'));
        $this->assertSame('public_id', config('consistentapi.uuid_column'));
        $this->assertSame('items', config('consistentapi.pagination.data_container_name'));
        $this->assertSame(['eq', 'like', 'from', 'to', 'in'], config('consistentapi.filters.operators'));
        $this->assertSame([], config('consistentapi.accesses'));
    }

    public function test_publishes_the_config_files(): void
    {
        $paths = ServiceProvider::pathsToPublish(ConsistentApiProvider::class, 'consistent-api-config');

        $this->assertSame([config_path('consistentapi.php')], array_values($paths));

        foreach (array_keys($paths) as $source) {
            $this->assertFileExists($source);
        }
    }

    public function test_registers_services_aliases_and_commands(): void
    {
        $resolver = $this->app->make(AccessResolver::class);
        $this->assertSame($resolver, $this->app->make(AccessResolver::class));
        $this->app->forgetScopedInstances();
        $this->assertNotSame($resolver, $this->app->make(AccessResolver::class));

        $aliases = $this->app['router']->getMiddleware();
        foreach (['consistent.api-json', 'consistent.ensure-json', 'consistent.ensure-multipart'] as $alias) {
            $this->assertArrayHasKey($alias, $aliases);
        }

        $this->assertArrayHasKey('consistent:create', Artisan::all());
        $this->assertArrayHasKey('consistent:rebuild', Artisan::all());
        $this->assertNull(JsonResource::$wrap);
    }

    public function test_prepends_api_json_to_the_global_middleware(): void
    {
        $kernel = $this->app->make(HttpKernel::class);

        $this->assertSame(ApiJsonMiddleware::class, $kernel->getGlobalMiddleware()[0]);
    }

    public function test_prepends_api_json_to_a_kernel_built_before_the_boot(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->setGlobalMiddleware([PassThroughMiddleware::class]);

        (new ConsistentApiProvider($this->app))->boot();

        $this->assertSame([ApiJsonMiddleware::class, PassThroughMiddleware::class], $kernel->getGlobalMiddleware());
    }

    #[DefineEnvironment('disableApiJson')]
    public function test_leaves_api_json_out_of_the_global_middleware_when_disabled(): void
    {
        $this->assertFalse($this->app->make(HttpKernel::class)->hasMiddleware(ApiJsonMiddleware::class));
        $this->assertArrayHasKey('consistent.api-json', $this->app['router']->getMiddleware());
    }

    protected function disableApiJson(Application $app): void
    {
        $app['config']->set('consistentapi.api_json_enabled', false);
    }

    public function test_resource_wrapping_is_left_alone_when_disabled(): void
    {
        JsonResource::wrap('data');
        config(['consistentapi.without_wrapping' => false]);

        (new ConsistentApiProvider($this->app))->boot();
        $this->assertSame('data', JsonResource::$wrap);

        config(['consistentapi.without_wrapping' => true]);

        (new ConsistentApiProvider($this->app))->boot();
        $this->assertNull(JsonResource::$wrap);
    }

    public function test_missing_models_do_not_reveal_their_class_under_the_api_prefix(): void
    {
        $this->createLibraryTables();
        Route::middleware('api')->get('api/books/{book}', fn (Book $book) => $book);
        Route::middleware('api')->get('web/books/{book}', fn (Book $book) => $book);
        config(['app.debug' => false]);

        $this->getJson('/api/books/999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->assertStringContainsString(Book::class, (string) $this->getJson('/web/books/999')->assertNotFound()->json('message'));

        config(['consistentapi.hide_model_not_found' => false]);
        $this->assertStringContainsString(Book::class, (string) $this->getJson('/api/books/999')->assertNotFound()->json('message'));

        config(['consistentapi.hide_model_not_found' => true, 'app.debug' => true]);
        $this->assertStringContainsString(Book::class, (string) $this->getJson('/api/books/999')->assertNotFound()->json('message'));
    }

    public function test_validates_models_on_boot_only_when_enabled_in_local_and_testing(): void
    {
        config(['consistentapi.filters.model_paths' => [dirname(__DIR__).'/InvalidModels/UnknownAccess']]);
        $provider = new ConsistentApiProvider($this->app);

        $this->app['env'] = 'production';
        $provider->boot();

        $this->app['env'] = 'local';
        config(['consistentapi.filters.validate_on_boot' => false]);
        $provider->boot();

        config(['consistentapi.filters.validate_on_boot' => true]);
        $this->expectException(InvalidAccessException::class);

        $provider->boot();
    }
}
