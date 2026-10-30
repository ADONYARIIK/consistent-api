<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CreateModuleCommandTest extends TestCase
{
    private string $module;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.accesses', [
            'verified' => ['auth:sanctum', 'verified'],
            'admin' => ['auth:sanctum', 'verified', 'can:admin'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = app_path('Modules/Posts');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Modules'));

        parent::tearDown();
    }

    /**
     * @return list<string> Paths relative to the module, sorted
     */
    private function generatedFiles(): array
    {
        $files = array_map(
            fn ($file) => str_replace('\\', '/', $file->getRelativePathname()),
            File::allFiles($this->module),
        );
        sort($files);

        return $files;
    }

    private function file(string $relative): string
    {
        return File::get($this->module.'/'.$relative);
    }

    public function test_description_uses_the_default_modules_folder(): void
    {
        $this->assertSame('Create an API module under app/Modules', Artisan::all()['consistent:create']->getDescription());
    }

    public function test_without_flags_creates_seven_files_and_five_routes(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post'])->assertSuccessful();

        $this->assertSame([
            'Controllers/PostController.php',
            'Models/Post.php',
            'Requests/SearchPostRequest.php',
            'Requests/StorePostRequest.php',
            'Requests/UpdatePostRequest.php',
            'Resources/PostResource.php',
            'Routes.php',
        ], $this->generatedFiles());

        $routes = $this->file('Routes.php');
        $this->assertSame(5, substr_count($routes, 'Route::'));
        $this->assertStringContainsString(
            "Route::match(['put', 'patch'], 'posts/{post}', [PostController::class, 'update'])->whereNumber('post')->name('posts.update');",
            $routes,
        );
        $this->assertStringNotContainsString('whereUuid', $routes);

        $model = $this->file('Models/Post.php');
        $this->assertStringContainsString('class Post extends ConsistentModel', $model);
        $this->assertStringContainsString(
            "#[Table('posts')]\n#[Fillable([])]\n#[Hidden([])]\n#[Filterable([])]\n#[Sortable([])]\nclass Post extends ConsistentModel {}\n",
            $model,
        );
        $this->assertStringNotContainsString('HasUuids', $model);
        $this->assertStringNotContainsString('RouteKey', $model);

        $this->assertGeneratedCodeIsClean();
    }

    public function test_get_only_creates_index_and_show(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--routes' => ['get']])->assertSuccessful();

        $controller = $this->file('Controllers/PostController.php');
        $this->assertStringContainsString('public function index(', $controller);
        $this->assertStringContainsString('public function show(', $controller);
        $this->assertStringNotContainsString('public function store(', $controller);
        $this->assertStringNotContainsString('public function update(', $controller);
        $this->assertStringNotContainsString('public function destroy(', $controller);

        $this->assertFileExists($this->module.'/Requests/SearchPostRequest.php');
        $this->assertFileDoesNotExist($this->module.'/Requests/StorePostRequest.php');
        $this->assertFileDoesNotExist($this->module.'/Requests/UpdatePostRequest.php');
        $this->assertSame(2, substr_count($this->file('Routes.php'), 'Route::'));

        $this->assertGeneratedCodeIsClean();
    }

    public function test_access_for_unselected_method_fails(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--routes' => ['get'], '--accesses' => ['post-admin']])
            ->expectsOutputToContain('not selected in --routes')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->module);
    }

    public function test_unknown_access_fails(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--accesses' => ['all-unknown']])
            ->expectsOutputToContain('not defined in consistentapi.accesses')
            ->assertFailed();
    }

    public function test_put_access_without_patch_fails(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--accesses' => ['put-admin']])
            ->expectsOutputToContain('put and patch')
            ->assertFailed();
    }

    public function test_light_and_standard_resources_bind_index_to_light(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['l,s']])->assertSuccessful();

        $this->assertFileExists($this->module.'/Resources/PostLightResource.php');
        $this->assertFileExists($this->module.'/Resources/PostResource.php');

        $controller = $this->file('Controllers/PostController.php');
        $this->assertStringContainsString('protected string $resourceClass = PostResource::class;', $controller);
        $this->assertStringContainsString("'index' => PostLightResource::class,", $controller);

        $this->assertStringContainsString('class PostLightResource extends JsonResource', $this->file('Resources/PostLightResource.php'));
        $standard = $this->file('Resources/PostResource.php');
        $this->assertStringContainsString('class PostResource extends PostLightResource', $standard);
        $this->assertStringContainsString('...parent::toArray($request),', $standard);

        $this->assertGeneratedCodeIsClean();
    }

    public function test_all_resources_extend_the_nearest_lighter_one(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['all']])->assertSuccessful();

        $light = $this->file('Resources/PostLightResource.php');
        $this->assertStringContainsString('use Illuminate\Http\Resources\Json\JsonResource;', $light);
        $this->assertStringContainsString('class PostLightResource extends JsonResource', $light);
        $this->assertStringContainsString("'id' => \$this->id,", $light);

        foreach (['PostResource' => 'PostLightResource', 'PostAdminResource' => 'PostResource'] as $class => $parent) {
            $resource = $this->file('Resources/'.$class.'.php');
            $this->assertStringContainsString(sprintf('class %s extends %s', $class, $parent), $resource);
            $this->assertStringContainsString('...parent::toArray($request),', $resource);
            $this->assertStringNotContainsString('use Illuminate\Http\Resources\Json\JsonResource;', $resource);
            $this->assertStringNotContainsString("'id' =>", $resource);
        }

        $this->assertGeneratedCodeIsClean();
    }

    public function test_admin_extends_light_without_standard(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['l,a']])->assertSuccessful();

        $this->assertStringContainsString('class PostLightResource extends JsonResource', $this->file('Resources/PostLightResource.php'));
        $this->assertStringContainsString('class PostAdminResource extends PostLightResource', $this->file('Resources/PostAdminResource.php'));
        $this->assertFileDoesNotExist($this->module.'/Resources/PostResource.php');

        $this->assertGeneratedCodeIsClean();
    }

    public function test_admin_only_resource_becomes_default(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['a']])->assertSuccessful();

        $this->assertSame(['Resources/PostAdminResource.php'], array_values(array_filter(
            $this->generatedFiles(),
            fn (string $file) => str_starts_with($file, 'Resources/'),
        )));

        $controller = $this->file('Controllers/PostController.php');
        $this->assertStringContainsString('protected string $resourceClass = PostAdminResource::class;', $controller);
        $this->assertStringContainsString('protected array $resourceClasses = [];', $controller);
        $this->assertStringContainsString('class PostAdminResource extends JsonResource', $this->file('Resources/PostAdminResource.php'));

        $this->assertGeneratedCodeIsClean();
    }

    public function test_uuid_module(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--uuid' => true])
            ->expectsOutputToContain("\$table->uuid('public_id')->unique();")
            ->assertSuccessful();

        $model = $this->file('Models/Post.php');
        $this->assertStringContainsString("#[Table('posts')]\n#[RouteKey('public_id')]\n#[Fillable([])]\n", $model);
        $this->assertStringContainsString("class Post extends ConsistentModel\n{\n    use HasUuids;\n", $model);
        $this->assertStringContainsString("return ['public_id'];", $model);
        $this->assertStringNotContainsString('getRouteKeyName', $model);
        $this->assertStringContainsString("->whereUuid('post')", $this->file('Routes.php'));
        $this->assertStringContainsString("'id' => \$this->public_id,", $this->file('Resources/PostResource.php'));

        $this->assertGeneratedCodeIsClean();
    }

    public function test_uuid_fails_when_disabled_in_config(): void
    {
        config(['consistentapi.uuid' => false]);

        $this->artisan('consistent:create', ['model' => 'Post', '--uuid' => true])
            ->expectsOutputToContain('consistentapi.uuid')
            ->assertFailed();
    }

    public function test_rerun_without_force_fails_and_keeps_files(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post'])->assertSuccessful();
        File::put($this->module.'/Models/Post.php', 'edited');

        $this->artisan('consistent:create', ['model' => 'Post'])
            ->expectsOutputToContain('use --force to overwrite')
            ->assertFailed();
        $this->assertSame('edited', $this->file('Models/Post.php'));

        $this->artisan('consistent:create', ['model' => 'Post', '--force' => true])->assertSuccessful();
        $this->assertNotSame('edited', $this->file('Models/Post.php'));
    }

    public function test_routes_are_grouped_by_access_groups(): void
    {
        $this->artisan('consistent:create', [
            'model' => 'Post',
            '--routes' => ['get,post,patch,delete'],
            '--accesses' => ['all-verified,delete-admin'],
            '--resources' => ['l,s'],
            '--uuid' => true,
        ])->assertSuccessful();

        $this->assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Modules\Posts\Controllers\PostController;
            use Illuminate\Support\Facades\Route;

            Route::access('verified')->group(function () {
                Route::get('posts', [PostController::class, 'index'])->name('posts.index');
                Route::get('posts/{post}', [PostController::class, 'show'])->whereUuid('post')->name('posts.show');
                Route::post('posts', [PostController::class, 'store'])->name('posts.store');
                Route::patch('posts/{post}', [PostController::class, 'update'])->whereUuid('post')->name('posts.update');
            });

            Route::access('admin')->group(function () {
                Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereUuid('post')->name('posts.destroy');
            });

            PHP, $this->file('Routes.php'));

        $this->assertGeneratedCodeIsClean();
    }

    public function test_multi_word_and_acronym_names_follow_laravel_conventions(): void
    {
        $this->artisan('consistent:create', ['model' => 'BlogPost', '--resources' => ['all'], '--uuid' => true])->assertSuccessful();
        $this->artisan('consistent:create', ['model' => 'APIKey'])->assertSuccessful();

        $blog = app_path('Modules/BlogPosts');
        $routes = File::get($blog.'/Routes.php');
        $this->assertStringContainsString("Route::get('blog-posts/{blog_post}', [BlogPostController::class, 'show'])->whereUuid('blog_post')->name('blog-posts.show');", $routes);
        $this->assertStringContainsString('public function update(UpdateBlogPostRequest $request, BlogPost $blogPost): JsonResource', File::get($blog.'/Controllers/BlogPostController.php'));
        $this->assertStringContainsString("#[Table('blog_posts')]", File::get($blog.'/Models/BlogPost.php'));

        $keys = app_path('Modules/APIKeys');
        $this->assertStringContainsString("#[Table('api_keys')]", File::get($keys.'/Models/APIKey.php'));
        $this->assertStringContainsString("Route::delete('api-keys/{api_key}', [APIKeyController::class, 'destroy'])->whereNumber('api_key')->name('api-keys.destroy');", File::get($keys.'/Routes.php'));
        $this->assertStringContainsString('public function destroy(APIKey $apiKey): JsonResponse', File::get($keys.'/Controllers/APIKeyController.php'));

        $this->assertGeneratedCodeIsClean($blog);
        $this->assertGeneratedCodeIsClean($keys);
    }

    /**
     * @return array<string, array{string, string}> Model name, part of the error
     */
    public static function unusableNames(): array
    {
        return [
            'keyword' => ['list', 'reserved word'],
            'class keyword' => ['Class', 'reserved word'],
            'type name' => ['Int', 'reserved word'],
            'model import' => ['Fillable', 'clashes with Fillable imported by the generated model'],
            'uuid import' => ['HasUuids', 'clashes with HasUuids'],
            'controller import' => ['Consistent', 'ConsistentController, which clashes with ConsistentController'],
            'resource import' => ['Json', 'JsonResource, which clashes with JsonResource'],
            'search request import' => ['BaseSearchRequest', 'clashes with BaseSearchRequest'],
            'this variable' => ['This', 'controller variable $this'],
            'request variable' => ['Request', 'controller variable $request'],
            'middleware module' => ['Middleware', 'module Middleware'],
        ];
    }

    #[DataProvider('unusableNames')]
    public function test_rejects_names_the_generated_code_cannot_use(string $model, string $error): void
    {
        $this->artisan('consistent:create', ['model' => $model])
            ->expectsOutputToContain($error)
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(app_path('Modules'));
    }

    public function test_rejects_a_modules_folder_php_cannot_use(): void
    {
        foreach (['', '/', '../Outside', 'my-modules'] as $folder) {
            config(['consistentapi.modules_folder' => $folder]);

            $this->artisan('consistent:create', ['model' => 'Post'])
                ->expectsOutputToContain('consistentapi.modules_folder')
                ->assertFailed();
        }

        config(['consistentapi.modules_folder' => 'Domain\\\\Modules']);

        $this->artisan('consistent:create', ['model' => 'Post'])->assertSuccessful();
        $this->assertStringContainsString('namespace App\\Domain\\Modules\\Posts\\Models;', File::get(app_path('Domain/Modules/Posts/Models/Post.php')));

        File::deleteDirectory(app_path('Domain'));
    }

    public function test_refuses_a_second_model_in_one_module_even_with_force(): void
    {
        $this->artisan('consistent:create', ['model' => 'Datum'])->assertSuccessful();

        $this->artisan('consistent:create', ['model' => 'Data', '--force' => true])
            ->expectsOutputToContain('Module Data already holds the model Datum')
            ->assertFailed();

        $this->assertFileDoesNotExist(app_path('Modules/Data/Models/Data.php'));
    }

    public function test_force_lists_files_of_an_earlier_wider_run(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['all']])->assertSuccessful();

        $this->artisan('consistent:create', ['model' => 'Post', '--routes' => ['get'], '--force' => true])
            ->expectsOutputToContain('left in place: '.$this->module.'/Resources/PostAdminResource.php')
            ->expectsOutputToContain('left in place: '.$this->module.'/Requests/StorePostRequest.php')
            ->assertSuccessful();

        $this->assertFileExists($this->module.'/Resources/PostAdminResource.php');
    }

    public function test_failed_write_leaves_nothing_behind(): void
    {
        // A directory where Routes.php, the last file, goes makes the run fail after the other writes
        File::ensureDirectoryExists($this->module.'/Routes.php');

        $this->artisan('consistent:create', ['model' => 'Post', '--force' => true])
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertSame([], $this->generatedFiles());
        $this->assertDirectoryDoesNotExist($this->module.'/Models');
    }

    public function test_rejects_unknown_resources_next_to_all_and_unsafe_access_names(): void
    {
        $this->artisan('consistent:create', ['model' => 'Post', '--resources' => ['all,x']])
            ->expectsOutputToContain('Unknown --resources value "x"')
            ->assertFailed();

        config(['consistentapi.accesses' => ['bad?>' => ['auth']]]);

        $this->artisan('consistent:create', ['model' => 'Post', '--accesses' => ['all-bad?>']])
            ->expectsOutputToContain('may contain only letters, digits and _ . : -')
            ->assertFailed();
    }

    /**
     * php -l and Pint (with the package pint.json) on a copy outside vendor/, which Pint skips.
     */
    private function assertGeneratedCodeIsClean(?string $module = null): void
    {
        $copy = sys_get_temp_dir().'/consistent-api-'.bin2hex(random_bytes(4));
        File::copyDirectory($module ?? $this->module, $copy);

        try {
            foreach (File::allFiles($copy) as $file) {
                $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
                $lint->run();
                $this->assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());
            }

            $root = dirname(__DIR__, 2);
            $pint = new Process([$root.'/vendor/bin/pint', '--test', '-v', '--config='.$root.'/pint.json', $copy]);
            $pint->run();
            $this->assertTrue($pint->isSuccessful(), $pint->getOutput().$pint->getErrorOutput());
        } finally {
            File::deleteDirectory($copy);
        }
    }
}
