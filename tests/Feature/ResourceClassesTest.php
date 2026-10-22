<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\MultiResourcePostController;
use Tests\Fixtures\Post;
use Tests\Fixtures\SearchPostRequest;
use Tests\TestCase;

class ResourceClassesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(function () use ($router) {
            $router->get('posts', [MultiResourcePostController::class, 'index']);
            $router->get('admin/posts', [MultiResourcePostController::class, 'adminIndex']);
            $router->get('posts/{post}', [MultiResourcePostController::class, 'show'])->whereNumber('post');
        });
    }

    public function test_each_action_uses_its_own_resource(): void
    {
        $post = Post::create(['title' => 'First']);

        $this->getJson('/api/posts')->assertOk()->assertJsonPath('items.0.kind', 'light');
        $this->getJson('/api/admin/posts')->assertOk()->assertJsonPath('items.0.kind', 'admin');
        $this->getJson('/api/posts/'.$post->id)->assertOk()->assertJsonPath('title', 'First');
    }

    public function test_direct_call_without_router_uses_default_resource(): void
    {
        Post::create(['title' => 'First']);

        $request = SearchPostRequest::create('/api/posts');
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        $response = (new MultiResourcePostController)->index($request);

        $this->assertSame('First', $response->getData(true)['items'][0]['title']);
    }
}
