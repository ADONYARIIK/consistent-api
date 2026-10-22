<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs a module produced by consistent:create through real HTTP requests.
 */
class GeneratedModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function ($table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Modules'));

        parent::tearDown();
    }

    public function test_uuid_module_serves_crud_requests(): void
    {
        // index goes through PostLightResource, show and update through PostResource, which inherits the id from it
        $this->artisan('consistent:create', ['model' => 'Post', '--uuid' => true, '--resources' => ['all']])->assertSuccessful();

        spl_autoload_register(function (string $class) {
            $prefix = 'App\\Modules\\';

            $path = app_path('Modules/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php');

            if (str_starts_with($class, $prefix) && is_file($path)) {
                require_once $path;
            }
        });

        Route::middleware('api')->prefix('api')->group(app_path('Modules/Posts/Routes.php'));
        app('router')->getRoutes()->refreshNameLookups();

        $uuid = $this->postJson('/api/posts')->assertCreated()->json('id');

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertSame(1, (int) DB::table('posts')->value('id'));

        $this->getJson('/api/posts')->assertOk()->assertJsonPath('items.0.id', $uuid);
        $this->getJson('/api/posts/'.$uuid)->assertOk()->assertJsonPath('id', $uuid);
        $this->getJson('/api/posts/1')->assertNotFound();
        $this->putJson('/api/posts/'.$uuid)->assertOk();
        $this->patchJson('/api/posts/'.$uuid)->assertOk();
        $this->deleteJson('/api/posts/'.$uuid)->assertNoContent();
        $this->assertSame(0, DB::table('posts')->count());
    }
}
