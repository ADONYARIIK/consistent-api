<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Post;
use Tests\Fixtures\PostController;
use Tests\TestCase;

class ApiRuntimeTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubMiddlewareAliases('auth', 'verified', 'can');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->group(function () use ($router) {
            $router->get('posts', [PostController::class, 'index'])->name('posts.index');
            $router->get('posts/{post}', [PostController::class, 'show'])->whereNumber('post')->name('posts.show');
        });
    }

    public function test_index_returns_paginated_items(): void
    {
        Post::create(['title' => 'First']);
        Post::create(['title' => 'Second']);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonCount(2, 'items');
    }

    public function test_index_filters_by_allowed_column(): void
    {
        Post::create(['title' => 'First']);
        Post::create(['title' => 'Second']);

        $this->getJson('/api/posts?filter[title]=First')
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function test_index_rejects_filter_by_unknown_column(): void
    {
        $this->getJson('/api/posts?filter[secret]=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.secret']);
    }

    public function test_show_returns_resource(): void
    {
        $post = Post::create(['title' => 'First']);

        $this->getJson('/api/posts/'.$post->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $post->id, 'title' => 'First']);
    }
}
