<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprint;
use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprintFactory;
use Adonyarik\ConsistentApi\Console\Generators\ModuleGenerator;
use Adonyarik\ConsistentApi\Console\Generators\ResourceKind;
use Adonyarik\ConsistentApi\Console\Generators\RouteAction;
use Adonyarik\ConsistentApi\Exceptions\InvalidModuleOptionsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ModuleGeneratorTest extends TestCase
{
    private function blueprint(): ModuleBlueprint
    {
        return (new ModuleBlueprintFactory('/app/', '/Domain/Modules/', [], true, 'public_id'))->make('blog post', ['get'], [], ['s'], false);
    }

    public function test_derives_names_and_paths_from_the_model(): void
    {
        $blueprint = $this->blueprint();

        $this->assertSame('BlogPost', $blueprint->model);
        $this->assertSame('BlogPosts', $blueprint->module);
        $this->assertSame('App\\Domain\\Modules\\BlogPosts', $blueprint->namespace);
        $this->assertSame('/app/Domain/Modules/BlogPosts', $blueprint->path);
        $this->assertSame('blog_posts', $blueprint->table);
        $this->assertSame('blog-posts', $blueprint->uri);
        $this->assertSame('blog_post', $blueprint->parameter);
        $this->assertSame('blogPost', $blueprint->variable());
        $this->assertTrue($blueprint->hasAction(RouteAction::Show));
        $this->assertFalse($blueprint->hasAction(RouteAction::Store));
        $this->assertSame(['Search'], $blueprint->requestPrefixes());
    }

    public function test_renders_every_file_under_the_module_path(): void
    {
        $files = (new ModuleGenerator)->generate($this->blueprint());

        $this->assertSame([
            '/app/Domain/Modules/BlogPosts/Models/BlogPost.php',
            '/app/Domain/Modules/BlogPosts/Controllers/BlogPostController.php',
            '/app/Domain/Modules/BlogPosts/Requests/SearchBlogPostRequest.php',
            '/app/Domain/Modules/BlogPosts/Resources/BlogPostResource.php',
            '/app/Domain/Modules/BlogPosts/Routes.php',
        ], array_keys($files));
        $this->assertStringContainsString('namespace App\\Domain\\Modules\\BlogPosts\\Models;', $files['/app/Domain/Modules/BlogPosts/Models/BlogPost.php']);
        $this->assertStringContainsString("Route::get('blog-posts/{blog_post}'", $files['/app/Domain/Modules/BlogPosts/Routes.php']);
    }

    public function test_missing_stub_fails(): void
    {
        // expectExceptionMessage() is deprecated since PHPUnit 13.2, see Tests\TestCase::expectExceptionMessageToContain()
        try {
            (new ModuleGenerator('/nowhere'))->generate($this->blueprint());
            $this->fail('A missing stub was not reported');
        } catch (RuntimeException $e) {
            $this->assertSame('Stub not found: /nowhere/consistent/model.stub', $e->getMessage());
        }
    }

    public function test_default_resource_without_resources_is_standard(): void
    {
        $blueprint = new ModuleBlueprint('Post', 'Posts', 'App\\Modules\\Posts', '/app/Modules/Posts', 'posts', 'posts', 'post', [], [], null);

        $this->assertSame(ResourceKind::Standard, $blueprint->defaultResource());
        $this->assertNull($blueprint->parentResource(ResourceKind::Light));
        $this->assertSame([], $blueprint->resourceOverrides());
    }

    public function test_access_group_must_list_non_empty_names(): void
    {
        $factory = new ModuleBlueprintFactory('/app', 'Modules', ['broken' => ['auth', '']], false, '');

        try {
            $factory->make('Post', [], ['all-broken'], [], false);
            $this->fail('A broken access group was accepted');
        } catch (InvalidModuleOptionsException $e) {
            $this->assertSame(['Access group "broken" in consistentapi.accesses must be an array of middleware names.'], $e->errors);
        }
    }
}
