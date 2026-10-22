<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * consistent:rebuild runs in a temporary application directory, so it never touches the Testbench skeleton.
 */
class RebuildCommandTest extends TestCase
{
    private const API_ROUTES = <<<'PHP'
        <?php

        use App\Http\Controllers\Api\PostController;
        use App\Http\Controllers\PostCommentController;
        use Illuminate\Support\Facades\Route;

        Route::get('posts', [PostController::class, 'index']);

        Route::middleware('auth')->group(function () {
            Route::get('comments', [PostCommentController::class, 'index']);
        });

        Route::get('semi', fn () => 'a;b\'c');

        // Route:: without an end

        PHP;

    private string $sandbox;

    /**
     * @var array<string, string> Relative path => contents written by the test
     */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/consistent-rebuild-'.bin2hex(random_bytes(4));

        foreach (['app/Models', 'routes', 'database', 'tests', 'storage/app'] as $directory) {
            File::ensureDirectoryExists($this->sandbox.'/'.$directory);
        }

        $this->app->setBasePath($this->sandbox);
        $this->app->useAppPath($this->sandbox.'/app');
        $this->app->useStoragePath($this->sandbox.'/storage');
        $this->resetApplicationNamespace();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * Application::getNamespace() caches the namespace it read from composer.json.
     */
    private function resetApplicationNamespace(): void
    {
        (new ReflectionProperty(Application::class, 'namespace'))->setValue($this->app, null);
    }

    private function writeSandbox(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->sandbox.'/'.$path));
        File::put($this->sandbox.'/'.$path, $contents);
        $this->written[$path] = $contents;
    }

    private function readSandbox(string $path): string
    {
        return File::get($this->sandbox.'/'.$path);
    }

    private function sandboxHas(string $path): bool
    {
        return File::exists($this->sandbox.'/'.$path);
    }

    /**
     * Every file of the sandbox by relative path, for comparing whole trees.
     *
     * @return array<string, string>
     */
    private function sandboxTree(): array
    {
        $tree = [];

        foreach (File::allFiles($this->sandbox) as $file) {
            $tree[str_replace($this->sandbox.'/', '', $file->getPathname())] = $file->getContents();
        }

        ksort($tree);

        return $tree;
    }

    /**
     * php -l on every PHP file of the sandbox.
     */
    private function lintSandbox(): void
    {
        foreach (File::allFiles($this->sandbox) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $lint = new Process([PHP_BINARY, '-l', $file->getPathname()]);
            $lint->run();
            $this->assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());
        }
    }

    /**
     * Pint with the package pint.json on copies outside vendor/, which Pint skips.
     *
     * @param  list<string>  $paths  Sandbox-relative files
     */
    private function assertPintClean(array $paths): void
    {
        $copy = sys_get_temp_dir().'/consistent-pint-'.bin2hex(random_bytes(4));

        try {
            foreach ($paths as $index => $path) {
                File::ensureDirectoryExists($copy.'/'.$index);
                File::copy($this->sandbox.'/'.$path, $copy.'/'.$index.'/'.basename($path));
            }

            $root = dirname(__DIR__, 2);
            $pint = new Process([$root.'/vendor/bin/pint', '--test', '-v', '--config='.$root.'/pint.json', $copy]);
            $pint->run();
            $this->assertTrue($pint->isSuccessful(), $pint->getOutput().$pint->getErrorOutput());
        } finally {
            File::deleteDirectory($copy);
        }
    }

    private function phpClass(string $namespace, string $declaration, string $uses = ''): string
    {
        return "<?php\n\nnamespace {$namespace};\n\n".($uses === '' ? '' : $uses."\n\n").$declaration."\n";
    }

    /**
     * An Eloquent model in App\Models.
     */
    private function putModel(string $name, string $body = '{}', string $uses = ''): void
    {
        $this->writeSandbox('app/Models/'.$name.'.php', $this->phpClass(
            'App\\Models',
            "class {$name} extends Model".($body === '{}' ? ' {}' : "\n".$body),
            trim("use Illuminate\\Database\\Eloquent\\Model;\n".$uses),
        ));
    }

    private function putController(string $path, string $namespace, string $name): void
    {
        $this->writeSandbox($path, $this->phpClass($namespace, "class {$name} {}"));
    }

    private function rebuild(array $options = []): PendingCommand
    {
        return $this->artisan('consistent:rebuild', ['--force' => true, ...$options]);
    }

    /**
     * A conventional application whose classes reach each other by short name through shared namespaces,
     * as Laravel generates them: controllers extend Controller and models reference models without imports.
     */
    private function putConventionalApp(): void
    {
        $this->putModel('Post', "{\n    public const DEFAULT_STATUS = Status::Draft;\n}");
        $this->writeSandbox('app/Models/Status.php', $this->phpClass('App\\Models', "enum Status: string\n{\n    case Draft = 'draft';\n}"));
        $this->putModel('PostComment', "{\n    public function post()\n    {\n        return \$this->belongsTo(Post::class);\n    }\n}");
        // Short names that are not class references must not be imported: a method named Post, a call,
        // a string and a comment; Status is imported from elsewhere under that name
        $this->writeSandbox('app/Models/Tag.php', $this->phpClass(
            'App\\Models',
            "if (! class_exists(Model::class)) {\n    return;\n}\n\n#[\\AllowDynamicProperties]\nfinal class Tag extends Model\n{\n    use Concerns\\HasSlug;\n\n"
                ."    public function make(): object\n    {\n        return new class {};\n    }\n\n"
                ."    public function Post(): string\n    {\n        // Post\n        return 'Post'.\$this->Post().Status::class;\n    }\n}",
            "use Illuminate\\Database\\Eloquent\\Model;\nuse Vendor\\Package\\Status;",
        ));
        $this->writeSandbox('app/Models/README.md', 'Not a class');
        $this->writeSandbox('app/Http/Resources/Tag.php', $this->phpClass('App\\Http\\Resources', 'class Tag {}'));
        $this->writeSandbox('app/Models/Concerns/HasSlug.php', $this->phpClass('App\\Models\\Concerns', 'trait HasSlug {}'));
        $this->writeSandbox('app/Http/Controllers/Controller.php', $this->phpClass('App\\Http\\Controllers', 'abstract class Controller {}'));
        $this->writeSandbox('app/Http/Controllers/Api/PostController.php', $this->phpClass(
            'App\\Http\\Controllers\\Api',
            "final class PostController extends Controller\n{\n    public function index(): string\n    {\n        return Post::class;\n    }\n}",
            "use App\\Http\\Controllers\\Controller;\nuse App\\Models\\Post;",
        ));
        $this->writeSandbox('app/Http/Controllers/PostCommentController.php', $this->phpClass('App\\Http\\Controllers', 'class PostCommentController extends Controller {}'));
        $this->writeSandbox('app/Http/Controllers/PosterController.php', $this->phpClass(
            'App\\Http\\Controllers',
            "class PosterController extends Controller\n{\n    public const NEXT = PostCommentController::class;\n}",
        ));
        $this->writeSandbox('app/Http/Requests/StorePostRequest.php', $this->phpClass('App\\Http\\Requests', 'class StorePostRequest {}'));
        $this->writeSandbox('app/Resources/PostResource.php', $this->phpClass('App\\Resources', 'class PostResource {}'));
        $this->writeSandbox('app/Services/Stats.php', $this->phpClass(
            'App\\Services',
            "class Stats\n{\n    public const CLASSES = [\\App\\Models\\Post::class, 'App\\Models\\PostComment', 'App\\Models\\PostComments'];\n}",
        ));
        $this->writeSandbox('tests/Feature/PostTest.php', $this->phpClass('Tests\\Feature', 'class PostTest {}', 'use App\\Models\\Post;'));
        $this->writeSandbox('database/factories/PostFactory.php', $this->phpClass('Database\\Factories', "class PostFactory\n{\n    protected \$model = \\App\\Models\\Post::class;\n}"));
        $this->writeSandbox('routes/api.php', self::API_ROUTES);
    }

    public function test_description_uses_the_default_modules_folder(): void
    {
        $this->assertSame('Rebuild conventional API classes into modules under app/Modules', Artisan::all()['consistent:rebuild']->getDescription());
    }

    public function test_moves_classes_into_modules_and_rewrites_references(): void
    {
        $this->putConventionalApp();
        $this->writeSandbox('app/Modules/PostComments/Routes.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('existing', fn () => 'ok');\n");

        $this->rebuild()
            ->expectsOutputToContain('Moved 8 class(es) into app/Modules.')
            ->expectsOutputToContain('Relocated 2 route statement(s)')
            ->assertSuccessful();

        $moved = [
            'app/Models/Post.php' => 'app/Modules/Posts/Models/Post.php',
            'app/Models/PostComment.php' => 'app/Modules/PostComments/Models/PostComment.php',
            'app/Models/Tag.php' => 'app/Modules/Tags/Models/Tag.php',
            'app/Http/Controllers/Api/PostController.php' => 'app/Modules/Posts/Controllers/PostController.php',
            'app/Http/Controllers/PostCommentController.php' => 'app/Modules/PostComments/Controllers/PostCommentController.php',
            'app/Http/Requests/StorePostRequest.php' => 'app/Modules/Posts/Requests/StorePostRequest.php',
            'app/Resources/PostResource.php' => 'app/Modules/Posts/Resources/PostResource.php',
            'app/Http/Resources/Tag.php' => 'app/Modules/Tags/Resources/Tag.php',
        ];

        foreach ($moved as $source => $destination) {
            $this->assertFalse($this->sandboxHas($source), $source);
            $this->assertTrue($this->sandboxHas($destination), $destination);
        }

        foreach (['app/Models/README.md', 'app/Models/Status.php', 'app/Models/Concerns/HasSlug.php', 'app/Http/Controllers/Controller.php', 'app/Http/Controllers/PosterController.php'] as $kept) {
            $this->assertTrue($this->sandboxHas($kept), $kept);
        }

        $controller = $this->readSandbox('app/Modules/Posts/Controllers/PostController.php');
        $this->assertStringContainsString('namespace App\\Modules\\Posts\\Controllers;', $controller);
        $this->assertStringContainsString("use App\\Http\\Controllers\\Controller;\nuse App\\Modules\\Posts\\Models\\Post;", $controller);
        $this->assertSame(1, substr_count($controller, 'use App\\Modules\\Posts\\Models\\Post;'));

        $this->lintSandbox();
    }

    public function test_imports_classes_that_were_reached_through_a_shared_namespace(): void
    {
        $this->putConventionalApp();

        $this->rebuild()->assertSuccessful();

        // Moved away from the base Controller it extended by short name
        $this->assertSame(
            "<?php\n\nnamespace App\\Modules\\PostComments\\Controllers;\n\nuse App\\Http\\Controllers\\Controller;\n\nclass PostCommentController extends Controller {}\n",
            $this->readSandbox('app/Modules/PostComments/Controllers/PostCommentController.php'),
        );
        // Moved away from Post, which moved to another module
        $this->assertStringContainsString(
            "use App\\Modules\\Posts\\Models\\Post;\nuse Illuminate\\Database\\Eloquent\\Model;",
            $this->readSandbox('app/Modules/PostComments/Models/PostComment.php'),
        );
        // The enum stays in App\Models and keeps its old name
        $this->assertStringContainsString('use App\\Models\\Status;', $this->readSandbox('app/Modules/Posts/Models/Post.php'));
        // A class that stays in place reaches a moved neighbour through its new name
        $this->assertStringContainsString(
            'use App\\Modules\\PostComments\\Controllers\\PostCommentController;',
            $this->readSandbox('app/Http/Controllers/PosterController.php'),
        );

        $tag = $this->readSandbox('app/Modules/Tags/Models/Tag.php');
        // A relative qualified name keeps resolving through its imported first segment
        $this->assertStringContainsString('use App\\Models\\Concerns;', $tag);
        $this->assertStringContainsString('    use Concerns\\HasSlug;', $tag);
        $this->assertStringNotContainsString('Models\\Post;', $tag);
        $this->assertStringNotContainsString('use App\\Models\\Status;', $tag);

        $this->lintSandbox();

        $this->assertStringContainsString(
            "[\\App\\Modules\\Posts\\Models\\Post::class, 'App\\Modules\\PostComments\\Models\\PostComment', 'App\\Models\\PostComments']",
            $this->readSandbox('app/Services/Stats.php'),
        );
        $this->assertStringContainsString('use App\\Modules\\Posts\\Models\\Post;', $this->readSandbox('tests/Feature/PostTest.php'));
        $this->assertStringContainsString('\\App\\Modules\\Posts\\Models\\Post::class', $this->readSandbox('database/factories/PostFactory.php'));
    }

    public function test_moves_route_statements_of_moved_controllers(): void
    {
        $this->putConventionalApp();
        $this->writeSandbox('app/Modules/PostComments/Routes.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('existing', fn () => 'ok');\n");

        $this->rebuild()->assertSuccessful();

        $this->assertSame(
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('semi', fn () => 'a;b\\'c');\n\n// Route:: without an end\n",
            $this->readSandbox('routes/api.php'),
        );

        $this->assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Modules\\Posts\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\n\n"
                ."Route::get('posts', [PostController::class, 'index']);\n",
            $this->readSandbox('app/Modules/Posts/Routes.php'),
        );

        $this->assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Modules\\PostComments\\Controllers\\PostCommentController;\nuse Illuminate\\Support\\Facades\\Route;\n\n"
                ."Route::get('existing', fn () => 'ok');\n\n"
                ."Route::middleware('auth')->group(function () {\n    Route::get('comments', [PostCommentController::class, 'index']);\n});\n",
            $this->readSandbox('app/Modules/PostComments/Routes.php'),
        );

        $this->assertPintClean(['app/Modules/Posts/Routes.php', 'app/Modules/PostComments/Routes.php']);
    }

    public function test_reports_an_app_without_models(): void
    {
        $this->putController('app/Http/Controllers/PostController.php', 'App\\Http\\Controllers', 'PostController');

        $this->artisan('consistent:rebuild')
            ->expectsOutput('No models were found in app/Models.')
            ->assertSuccessful();

        $this->assertTrue($this->sandboxHas('app/Http/Controllers/PostController.php'));
        $this->assertTrue(File::isDirectory($this->sandbox.'/app/Modules'));
    }

    public function test_leaves_routes_alone_when_no_statement_uses_a_moved_controller(): void
    {
        $this->putModel('Post');
        $this->putController('app/Http/Controllers/PostController.php', 'App\\Http\\Controllers', 'PostController');
        $routes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('ping', fn () => 'pong');\n";
        $this->writeSandbox('routes/api.php', $routes);

        $this->rebuild()
            ->doesntExpectOutputToContain('Relocated')
            ->assertSuccessful();

        $this->assertSame($routes, $this->readSandbox('routes/api.php'));
        $this->assertTrue($this->sandboxHas('app/Modules/Posts/Controllers/PostController.php'));
        $this->assertFalse($this->sandboxHas('app/Modules/Posts/Routes.php'));
    }

    public function test_works_without_api_routes_or_controllers(): void
    {
        File::deleteDirectory($this->sandbox.'/tests');
        File::deleteDirectory($this->sandbox.'/database');
        $this->putModel('Post');

        $this->rebuild()->assertSuccessful();
        $this->assertTrue($this->sandboxHas('app/Modules/Posts/Models/Post.php'));

        $this->putModel('Tag');
        $this->writeSandbox('routes/api.php', "<?php\n");

        $this->rebuild()->assertSuccessful();
        $this->assertTrue($this->sandboxHas('app/Modules/Tags/Models/Tag.php'));
        $this->assertSame("<?php\n", $this->readSandbox('routes/api.php'));
    }

    public function test_refuses_two_classes_with_one_destination_before_moving_anything(): void
    {
        $this->putModel('Post');
        $this->putController('app/Http/Controllers/Api/PostController.php', 'App\\Http\\Controllers\\Api', 'PostController');
        $this->putController('app/Http/Controllers/Admin/PostController.php', 'App\\Http\\Controllers\\Admin', 'PostController');
        $before = $this->sandboxTree();

        $this->rebuild()
            ->expectsOutputToContain('Several classes have the same destination: '.$this->sandbox.'/app/Modules/Posts/Controllers/PostController.php')
            ->assertFailed();

        $this->assertSame($before, $this->sandboxTree());
        $this->assertFalse($this->sandboxHas('app/Modules/Posts'));
    }

    public function test_refuses_an_existing_destination_before_moving_anything(): void
    {
        $this->putModel('Post');
        $this->writeSandbox('app/Modules/Posts/Models/Post.php', 'existing');

        $this->rebuild()
            ->expectsOutputToContain('Destination already exists')
            ->assertFailed();

        $this->assertTrue($this->sandboxHas('app/Models/Post.php'));
        $this->assertSame('existing', $this->readSandbox('app/Modules/Posts/Models/Post.php'));
    }

    public function test_failed_run_puts_every_file_back(): void
    {
        $this->putConventionalApp();
        // A file where the Tags module folder goes makes a write fail after other files were written
        $this->writeSandbox('app/Modules/Tags', 'not a folder');

        $this->rebuild()
            ->expectsOutputToContain('consistent:rebuild failed')
            ->expectsOutputToContain('Every file was put back.')
            ->assertFailed();

        foreach ($this->written as $path => $contents) {
            $this->assertSame($contents, $this->readSandbox($path), $path);
        }

        // The folders the run created are gone again, and so is the journal
        $this->assertFalse($this->sandboxHas('app/Modules/Posts'));
        $this->assertFalse($this->sandboxHas('app/Modules/PostComments'));
        $this->assertFalse($this->sandboxHas('storage/app/consistent-rebuild.json'));
    }

    public function test_dry_run_and_declined_confirmation_change_nothing(): void
    {
        $this->putConventionalApp();
        $before = $this->sandboxTree();

        $this->artisan('consistent:rebuild', ['--dry-run' => true])
            ->expectsOutputToContain('App\\Models\\Post → App\\Modules\\Posts\\Models\\Post')
            ->expectsOutputToContain('App\\Http\\Controllers\\PosterController: no model matches its name')
            ->expectsOutputToContain('Route statements moving from routes/api.php: 2')
            ->expectsOutputToContain('Dry run: no file was changed.')
            ->assertSuccessful();

        $this->assertSame($before, $this->sandboxTree());

        $this->artisan('consistent:rebuild')
            ->expectsConfirmation('Move 8 class(es) and write 15 file(s)?', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertSame($before, $this->sandboxTree());
    }

    public function test_production_requires_confirmation(): void
    {
        $this->putModel('Post');
        $this->app['env'] = 'production';

        // Prompts answer with their default, no, without a terminal
        $this->artisan('consistent:rebuild', ['--no-interaction' => true])
            ->expectsOutputToContain('APPLICATION IN PRODUCTION')
            ->expectsOutputToContain('Command cancelled.')
            ->assertFailed();

        $this->assertTrue($this->sandboxHas('app/Models/Post.php'));
    }

    public function test_rollback_restores_the_tree_from_the_journal(): void
    {
        $this->putConventionalApp();
        $before = $this->sandboxTree();

        $this->rebuild()->assertSuccessful();
        $this->assertTrue($this->sandboxHas('storage/app/consistent-rebuild.json'));

        // A file changed after the run stops the rollback
        $this->writeSandbox('app/Modules/Posts/Models/Post.php', "<?php\n// edited\n");

        $this->artisan('consistent:rebuild', ['--rollback' => true])
            ->expectsOutputToContain('Changed since the rebuild: '.$this->sandbox.'/app/Modules/Posts/Models/Post.php')
            ->assertFailed();

        $this->artisan('consistent:rebuild', ['--rollback' => true, '--force' => true])
            ->expectsOutputToContain('Restored')
            ->assertSuccessful();

        unset($before['app/Modules/Posts/Models/Post.php']);
        $this->assertSame($before, $this->sandboxTree());
        $this->assertFalse(File::isDirectory($this->sandbox.'/app/Modules/Posts'));

        $this->artisan('consistent:rebuild', ['--rollback' => true])
            ->expectsOutputToContain('There is nothing to roll back')
            ->assertFailed();
    }

    public function test_keeps_abstract_classes_and_classes_that_are_not_models(): void
    {
        $this->writeSandbox('app/Models/BaseModel.php', $this->phpClass('App\\Models', 'abstract class BaseModel extends Model {}', 'use Illuminate\\Database\\Eloquent\\Model;'));
        $this->writeSandbox('app/Models/Post.php', $this->phpClass('App\\Models', 'class Post extends BaseModel {}'));
        $this->writeSandbox('app/Models/User.php', $this->phpClass('App\\Models', 'class User extends Authenticatable {}', 'use Illuminate\\Foundation\\Auth\\User as Authenticatable;'));
        $this->writeSandbox('app/Models/Scopes/PublishedScope.php', $this->phpClass('App\\Models\\Scopes', 'class PublishedScope implements Scope {}', 'use Illuminate\\Database\\Eloquent\\Scope;'));
        $this->writeSandbox('app/Models/Casts/Money.php', $this->phpClass('App\\Models\\Casts', 'class Money {}'));
        $this->writeSandbox('app/Models/Several.php', $this->phpClass('App\\Models', "class Several extends Model {}\nclass SeveralStatus {}", 'use Illuminate\\Database\\Eloquent\\Model;'));

        $this->rebuild()
            ->expectsOutputToContain('App\\Models\\BaseModel: abstract class')
            ->expectsOutputToContain('App\\Models\\Scopes\\PublishedScope: not an Eloquent model')
            ->expectsOutputToContain('App\\Models\\Casts\\Money: not an Eloquent model')
            ->expectsOutputToContain('App\\Models\\Several: its file declares several classes')
            ->expectsOutputToContain('Moved 2 class(es)')
            ->assertSuccessful();

        // Through its abstract parent in app/Models, and through Authenticatable
        $this->assertTrue($this->sandboxHas('app/Modules/Posts/Models/Post.php'));
        $this->assertTrue($this->sandboxHas('app/Modules/Users/Models/User.php'));
        $this->assertStringContainsString('use App\\Models\\BaseModel;', $this->readSandbox('app/Modules/Posts/Models/Post.php'));

        foreach (['BaseModel.php', 'Scopes/PublishedScope.php', 'Casts/Money.php', 'Several.php'] as $kept) {
            $this->assertTrue($this->sandboxHas('app/Models/'.$kept), $kept);
        }

        $this->lintSandbox();
    }

    public function test_matches_components_to_models_without_guessing(): void
    {
        foreach (['Store', 'Post', 'Admin', 'Category'] as $model) {
            $this->putModel($model);
        }

        $components = [
            'app/Http/Requests/StorePostRequest.php' => 'Posts/Requests',
            'app/Http/Requests/UpdateStoreRequest.php' => 'Stores/Requests',
            'app/Http/Controllers/PostsController.php' => 'Posts/Controllers',
            'app/Http/Controllers/CategoriesController.php' => 'Categories/Controllers',
            'app/Http/Resources/PostLightResource.php' => 'Posts/Resources',
            'app/Http/Resources/PostCollection.php' => 'Posts/Resources',
            'app/Http/Resources/V2PostResource.php' => 'Posts/Resources',
        ];

        foreach (array_keys($components) as $path) {
            $folder = str_contains($path, 'Requests') ? 'Requests' : (str_contains($path, 'Resources') ? 'Resources' : 'Controllers');
            $this->putController($path, 'App\\Http\\'.$folder, basename($path, '.php'));
        }

        $this->putController('app/Http/Controllers/AdminPostController.php', 'App\\Http\\Controllers', 'AdminPostController');

        $this->rebuild()
            ->expectsOutputToContain('App\\Http\\Controllers\\AdminPostController: its name matches several models (Admin, Post)')
            ->assertSuccessful();

        foreach ($components as $path => $destination) {
            $this->assertTrue($this->sandboxHas('app/Modules/'.$destination.'/'.basename($path)), $path);
        }

        $this->assertTrue($this->sandboxHas('app/Http/Controllers/AdminPostController.php'));
    }

    public function test_route_groups_conditions_and_comments_keep_their_meaning(): void
    {
        $this->putModel('Post');
        $this->putModel('Tag');
        $this->putController('app/Http/Controllers/PostController.php', 'App\\Http\\Controllers', 'PostController');
        $this->putController('app/Http/Controllers/TagController.php', 'App\\Http\\Controllers', 'TagController');
        $this->writeSandbox('app/Http/Middleware/EnsureAdmin.php', $this->phpClass('App\\Http\\Middleware', 'class EnsureAdmin {}'));
        $this->writeSandbox('routes/api.php', <<<'PHP'
            <?php

            use App\Http\Controllers\PostController;
            use App\Http\Controllers\PostController as Posts;
            use App\Http\Controllers\TagController;
            use App\Http\Middleware\EnsureAdmin;
            use Illuminate\Support\Facades\Route;

            Route::middleware('auth')->group(function () { // Don't cache these (
                Route::get('posts', [PostController::class, 'index'])->middleware(EnsureAdmin::class);
            });

            if (app()->isLocal()) {
                Route::get('debug/posts', [PostController::class, 'debug']);
            }

            // Route::delete('posts', [PostController::class, 'purgeAll']); // disabled: dangerous

            // Route::apiResource registers index, store, show, update and destroy
            \Illuminate\Support\Facades\Route::get('tags', [TagController::class, 'index']);

            $posts = Route::get('aliased', [Posts::class, 'index']);

            Route::get('both', [PostController::class, 'index'])->middleware([TagController::class]);

            PHP);

        $this->rebuild()
            ->expectsOutputToContain('Relocated 2 route statement(s)')
            ->expectsOutputToContain('(not a Route:: statement)')
            ->expectsOutputToContain('(controllers of several modules: Posts, Tags)')
            ->assertSuccessful();

        $posts = $this->readSandbox('app/Modules/Posts/Routes.php');
        // The group moves whole: the route keeps auth, and its middleware is imported
        $this->assertStringContainsString("Route::middleware('auth')->group(function () { // Don't cache these (\n    Route::get('posts', [PostController::class, 'index'])->middleware(EnsureAdmin::class);\n});", $posts);
        $this->assertStringContainsString('use App\\Http\\Middleware\\EnsureAdmin;', $posts);
        $this->assertStringContainsString('use App\\Modules\\Posts\\Controllers\\PostController;', $posts);
        $this->assertStringNotContainsString('debug/posts', $posts);
        $this->assertStringNotContainsString('purgeAll', $posts);

        $this->assertStringContainsString("\\Illuminate\\Support\\Facades\\Route::get('tags', [TagController::class, 'index']);", $this->readSandbox('app/Modules/Tags/Routes.php'));

        $api = $this->readSandbox('routes/api.php');
        // The conditional route, the comments, the assignment and the mixed statement stay, with new class names
        $this->assertStringContainsString("if (app()->isLocal()) {\n    Route::get('debug/posts', [PostController::class, 'debug']);\n}", $api);
        $this->assertStringContainsString("// Route::delete('posts', [PostController::class, 'purgeAll']); // disabled: dangerous", $api);
        $this->assertStringContainsString('// Route::apiResource registers index, store, show, update and destroy', $api);
        $this->assertStringContainsString("\$posts = Route::get('aliased', [Posts::class, 'index']);", $api);
        $this->assertStringContainsString('use App\\Modules\\Posts\\Controllers\\PostController as Posts;', $api);
        $this->assertStringContainsString('use App\\Modules\\Posts\\Controllers\\PostController;', $api);
        $this->assertStringContainsString('use App\\Modules\\Tags\\Controllers\\TagController;', $api);
        $this->assertStringNotContainsString("Route::middleware('auth')", $api);

        $this->lintSandbox();
    }

    public function test_rewrites_config_and_points_moved_models_to_their_factories(): void
    {
        $this->writeSandbox('app/Models/User.php', $this->phpClass(
            'App\\Models',
            "class User extends Authenticatable\n{\n    /** @use HasFactory<\\Database\\Factories\\UserFactory> */\n    use HasFactory;\n}",
            "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;",
        ));
        $this->writeSandbox('database/factories/UserFactory.php', $this->phpClass('Database\\Factories', 'class UserFactory {}'));
        $this->writeSandbox('config/auth.php', "<?php\n\nreturn [\n    'providers' => [\n        'users' => ['model' => env('AUTH_MODEL', App\\Models\\User::class)],\n    ],\n];\n");
        $this->writeSandbox('bootstrap/providers.php', "<?php\n\nuse App\\Models\\User;\n\nreturn [User::class];\n");
        $this->writeSandbox('bootstrap/cache/config.php', "<?php return ['model' => 'App\\\\Models\\\\User'];\n");
        $this->writeSandbox('resources/views/user.blade.php', "@inject('users', 'App\\Models\\User')\n");
        $this->writeSandbox('tests/fixtures/user.json', "{\"type\": \"App\\\\Models\\\\User\"}\n");

        $this->rebuild()
            ->expectsOutputToContain('resources/views/user.blade.php:1')
            ->expectsOutputToContain('tests/fixtures/user.json:1')
            ->expectsOutputToContain("'App\\Models\\User' => \\App\\Modules\\Users\\Models\\User::class,")
            ->assertSuccessful();

        $this->assertStringContainsString("env('AUTH_MODEL', App\\Modules\\Users\\Models\\User::class)", $this->readSandbox('config/auth.php'));
        $this->assertStringContainsString('use App\\Modules\\Users\\Models\\User;', $this->readSandbox('bootstrap/providers.php'));
        // Generated caches are left for optimize:clear
        $this->assertSame($this->written['bootstrap/cache/config.php'], $this->readSandbox('bootstrap/cache/config.php'));

        $user = $this->readSandbox('app/Modules/Users/Models/User.php');
        $this->assertStringContainsString("#[UseFactory(UserFactory::class)]\nclass User extends Authenticatable", $user);
        $this->assertStringContainsString("use Database\\Factories\\UserFactory;\nuse Illuminate\\Database\\Eloquent\\Attributes\\UseFactory;", $user);

        $this->lintSandbox();
    }

    public function test_rewrites_unusual_but_valid_php(): void
    {
        $this->writeSandbox('app/Models/Post.php', "<?php namespace App\\Models; use Illuminate\\Database\\Eloquent\\Model; class Post extends Model {}\n");
        $this->writeSandbox('app/Models/Tag.php', "<?php\n\nnamespace App\\Models {\n    use Illuminate\\Database\\Eloquent\\Model;\n\n    class Tag extends Model {}\n}\n");
        $this->writeSandbox('app/Models/Post/Status.php', $this->phpClass('App\\Models\\Post', 'enum Status {}'));
        $this->writeSandbox('app/Services/Feed.php', $this->phpClass(
            'App\\Services',
            "class Feed\n{\n    public const MAP = ['post' => \"App\\\\Models\\\\Post\", 'tag' => 'App\\\\Models\\\\Tag'];\n\n"
                ."    public function classes(): array\n    {\n        return [Post::class, T::class, \\Legacy\\App\\Models\\Post::class, Models\\Tag::class, Status::class];\n    }\n}",
            "use App\\Models;\nuse App\\Models\\{Post, Tag as T};\nuse App\\Models\\Post\\Status;",
        ));
        $this->writeSandbox('app/Models/Comment.php', $this->phpClass(
            'App\\Models',
            "/**\n * @property-read Post \$post\n */\nclass Comment extends Model {}",
            'use Illuminate\\Database\\Eloquent\\Model;',
        ));
        $this->writeSandbox('app/Services/Mover.php', $this->phpClass('App\\Models\\Support', "class Mover\n{\nuse Movable;\n\n    public function post(): \\App\\Models\\Post\n    {\n        return new \\App\\Models\\Post;\n    }\n}"));

        $this->rebuild()->assertSuccessful();

        $this->assertStringStartsWith('<?php namespace App\\Modules\\Posts\\Models;', $this->readSandbox('app/Modules/Posts/Models/Post.php'));
        $this->assertStringContainsString('namespace App\\Modules\\Tags\\Models {', $this->readSandbox('app/Modules/Tags/Models/Tag.php'));

        $feed = $this->readSandbox('app/Services/Feed.php');
        $this->assertStringContainsString("use App\\Modules\\Posts\\Models\\Post;\nuse App\\Modules\\Tags\\Models\\Tag as T;", $feed);
        $this->assertStringContainsString('"App\\\\Modules\\\\Posts\\\\Models\\\\Post"', $feed);
        $this->assertStringContainsString("'App\\\\Modules\\\\Tags\\\\Models\\\\Tag'", $feed);
        $this->assertStringContainsString('\\Legacy\\App\\Models\\Post::class', $feed);
        $this->assertStringContainsString('\\App\\Modules\\Tags\\Models\\Tag::class', $feed);
        // A class below the namespace named like a model is another class: it stays, and so does its import
        $this->assertStringContainsString('use App\\Models\\Post\\Status;', $feed);
        $this->assertTrue($this->sandboxHas('app/Models/Post/Status.php'));
        $this->assertStringContainsString('namespace App\\Models\\Post;', $this->readSandbox('app/Models/Post/Status.php'));

        // A class named only in a docblock gets an import
        $this->assertStringContainsString('use App\\Modules\\Posts\\Models\\Post;', $this->readSandbox('app/Modules/Comments/Models/Comment.php'));

        // A trait use at the start of the class body is not taken for the import block
        $mover = $this->readSandbox('app/Services/Mover.php');
        $this->assertStringContainsString("{\nuse Movable;\n", $mover);
        $this->assertStringContainsString('return new \\App\\Modules\\Posts\\Models\\Post;', $mover);

        $this->lintSandbox();
    }

    public function test_refuses_a_file_it_cannot_parse_that_names_a_moved_class(): void
    {
        $this->putModel('Post');
        $this->writeSandbox('tests/Broken.php', "<?php\n\nuse App\\Models\\Post;\n\nfunction (\n");
        $this->writeSandbox('tests/Unrelated.php', "<?php\n\nfunction (\n");
        $before = $this->sandboxTree();

        $this->rebuild()
            ->expectsOutputToContain($this->sandbox.'/tests/Broken.php cannot be parsed')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertSame($before, $this->sandboxTree());
    }

    public function test_uses_the_application_namespace_and_rejects_an_invalid_modules_folder(): void
    {
        $this->writeSandbox('composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]));
        $this->resetApplicationNamespace();
        $this->putModel('Post');

        $this->rebuild()->assertSuccessful();
        $this->assertStringContainsString('namespace Acme\\Modules\\Posts\\Models;', $this->readSandbox('app/Modules/Posts/Models/Post.php'));

        config(['consistentapi.modules_folder' => 'my-modules']);
        $this->putModel('Tag');

        $this->rebuild()
            ->expectsOutputToContain('consistentapi.modules_folder "my-modules" is not valid')
            ->assertFailed();

        $this->assertTrue($this->sandboxHas('app/Models/Tag.php'));
    }
}
