<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands;

use Adonyarik\ConsistentApi\Console\Commands\Concerns\ResolvesModulesFolder;
use Adonyarik\ConsistentApi\Console\Generators\ModuleGenerator;
use Adonyarik\ConsistentApi\Console\Rebuild\ApiRoutesRelocator;
use Adonyarik\ConsistentApi\Console\Rebuild\Move;
use Adonyarik\ConsistentApi\Console\Rebuild\ParsedFile;
use Adonyarik\ConsistentApi\Console\Rebuild\PhpSourceRewriter;
use Adonyarik\ConsistentApi\Console\Rebuild\RebuildException;
use Adonyarik\ConsistentApi\Console\Rebuild\RebuildPlan;
use Adonyarik\ConsistentApi\Console\Rebuild\RebuildPlanner;
use Adonyarik\ConsistentApi\Console\Support\FileTransaction;
use Adonyarik\ConsistentApi\Support\ModulesFolder;
use Adonyarik\ConsistentApi\Support\PhpClassReader;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\File;
use JsonException;
use ParseError;
use PhpParser\Error;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Artisan command consistent:rebuild: moves a conventional Laravel layout into modules.
 *
 * Every concrete Eloquent model in app/Models becomes the model of the module {PluralModel} under
 * app/{modules_folder}; controllers, form requests and resources follow the model their name points to
 * (see RebuildPlanner). References to the moved classes are rewritten on the syntax tree in every PHP file
 * of app/, routes/, database/, tests/, config/ and bootstrap/, and the routes/api.php statements of moved
 * controllers move into the module's Routes.php (see ApiRoutesRelocator). Everything is computed and
 * checked in memory first: when a rewritten file would not parse, nothing is written. --dry-run prints the
 * plan only; otherwise the command asks for confirmation (always in production) unless --force is given.
 * The original contents of every touched file go into a journal, storage/app/consistent-rebuild.json,
 * before the first write, and --rollback restores them.
 *
 * @package Adonyarik\ConsistentApi\Console\Commands
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class RebuildCommand extends Command
{
    use ConfirmableTrait, ResolvesModulesFolder;

    /**
     * Command name and options.
     *
     * @var string
     */
    protected $signature = 'consistent:rebuild
        {--dry-run : Show what would move and change without touching any file}
        {--force : Do not ask for confirmation, also in production}
        {--rollback : Restore every file the last run changed, from its journal}';

    /**
     * Folders whose PHP files get their class references rewritten, relative to the project root.
     */
    private const REWRITTEN_FOLDERS = ['app', 'routes', 'database', 'tests', 'config', 'bootstrap'];

    /**
     * Folders whose other files are searched for the old class names, to report them.
     */
    private const REPORTED_FOLDERS = ['resources', 'database', 'tests', 'config'];

    /**
     * Sets the description with the configured modules folder.
     *
     * Command::__construct() hands $description to setDescription(), so it is set before the parent
     * constructor runs. The command is created when Artisan starts, after the config is loaded.
     *
     * @see ResolvesModulesFolder::modulesFolder()
     */
    public function __construct()
    {
        // Command::__construct() hands $description to setDescription(), so it is set first
        $this->description = sprintf('Rebuild conventional API classes into modules under app/%s', $this->modulesFolder());

        parent::__construct();
    }

    /**
     * Plans the run, shows it and, once confirmed, moves the classes and rewrites their references.
     *
     * Stops with Command::FAILURE, before any change, on an invalid modules folder, conflicting
     * destinations, a file that references a moved class but cannot be parsed, or a rewritten file that
     * would not parse. When a write fails, every file is put back and the command fails too.
     *
     * @return int Command::SUCCESS, or Command::FAILURE when the run stopped or was declined
     *
     * @see RebuildPlanner::plan()
     * @see RebuildCommand::computeChanges()
     * @see RebuildCommand::apply()
     * @see RebuildCommand::rollback()
     */
    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $folderError = $this->modulesFolderError();

        if ($folderError !== null) {
            $this->error($folderError);

            return Command::FAILURE;
        }

        $folder = ModulesFolder::fromConfig(config('consistentapi.modules_folder', 'Modules'));
        $modulesPath = $folder->path(app_path());
        $plan = (new RebuildPlanner(app_path(), $modulesPath, $folder->namespace($this->rootNamespace())))->plan();

        if ($plan->errors !== []) {
            return $this->stopWith(...$plan->errors);
        }

        if ($plan->modelMoves() === []) {
            $this->info('No models were found in app/Models.');
            $this->printKept($plan);

            if (! $this->option('dry-run')) {
                File::ensureDirectoryExists($modulesPath);
            }

            return Command::SUCCESS;
        }

        try {
            [$changes, $relocated, $left] = $this->computeChanges($plan, $modulesPath);
        } catch (RebuildException $exception) {
            return $this->stopWith($exception->getMessage(), 'Nothing was changed.');
        }

        $this->printPlan($plan, $changes, $relocated, $left);

        if ($this->option('dry-run')) {
            $this->info('Dry run: no file was changed.');

            return Command::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        $this->warnAboutUncommittedChanges();

        $writes = count(array_filter($changes, fn (?string $contents) => $contents !== null));

        if (! $this->option('force') && ! $this->confirm(sprintf('Move %d class(es) and write %d file(s)?', count($plan->moves), $writes))) {
            $this->warn('Nothing was changed.');

            return Command::FAILURE;
        }

        if (! $this->apply($changes)) {
            return Command::FAILURE;
        }

        $this->info(sprintf('Moved %d class(es) into app/%s.', count($plan->moves), $folder->relativePath));

        if ($relocated > 0) {
            $this->info(sprintf('Relocated %d route statement(s) from routes/api.php into module Routes.php files.', $relocated));
        }

        $this->printNextSteps($relocated);

        return Command::SUCCESS;
    }

    /**
     * Computes the contents of every file the run writes or deletes, without writing anything.
     *
     * Moved files get their new namespace (and #[UseFactory] when Laravel would no longer find their
     * factory) and go to their destination; their sources are deleted. Every other PHP file of the
     * rewritten folders gets its references rewritten. routes/api.php loses the statements that move to
     * module route files. Each result is checked with the PHP parser, and each moved file must declare the
     * planned class.
     *
     * @param  RebuildPlan  $plan  The plan
     * @param  string  $modulesPath  Absolute path of app/{modules_folder}
     *
     * @return array{0: array<string, string|null>, 1: int, 2: list<string>} Path => new contents (null to delete), number of relocated route statements, route statements left in routes/api.php
     *
     * @throws RebuildException When a file cannot be parsed or a result would not be valid PHP
     */
    private function computeChanges(RebuildPlan $plan, string $modulesPath): array
    {
        $rewriter = new PhpSourceRewriter($plan->classMap(), $plan->knownClasses);
        $movesBySource = [];
        $changes = [];
        $deletes = [];

        foreach ($plan->moves as $move) {
            $movesBySource[$move->source] = $move;
        }

        $apiPath = base_path('routes/api.php');

        foreach ($this->phpFiles() as $path) {
            if ($path === $apiPath) {
                continue;
            }

            $code = File::get($path);
            $move = $movesBySource[$path] ?? null;

            try {
                $rewritten = $rewriter->rewrite($code, $move?->namespace, $move?->type === 'Models' ? $this->factoryFor($move) : null);
            } catch (Error $error) {
                if ($move !== null || $this->mentionsMovedClass($code, $plan)) {
                    throw new RebuildException(sprintf('%s cannot be parsed (%s), so its references cannot be rewritten. Fix it first.', $path, $error->getMessage()));
                }

                continue;
            }

            if ($move !== null) {
                $changes[$move->destination] = $rewritten;
                $deletes[] = $move->source;
            } elseif ($rewritten !== $code) {
                $changes[$path] = $rewritten;
            }
        }

        [$relocated, $left] = File::exists($apiPath)
            ? $this->relocateRoutes($apiPath, $plan, $rewriter, $modulesPath, $changes)
            : [0, []];

        foreach ($deletes as $source) {
            $changes[$source] = null;
        }

        $this->assertValid($changes, $plan);

        return [$changes, $relocated, $left];
    }

    /**
     * Moves the route statements of moved controllers out of routes/api.php.
     *
     * @param  string  $apiPath  Absolute path of routes/api.php
     * @param  RebuildPlan  $plan  The plan
     * @param  PhpSourceRewriter  $rewriter  Rewriter of the run
     * @param  string  $modulesPath  Absolute path of app/{modules_folder}
     * @param  array<string, string|null>  $changes  Path => new contents, filled in
     *
     * @return array{0: int, 1: list<string>} Number of relocated statements and the statements left in place
     *
     * @throws RebuildException When routes/api.php or a module Routes.php cannot be parsed
     */
    private function relocateRoutes(string $apiPath, RebuildPlan $plan, PhpSourceRewriter $rewriter, string $modulesPath, array &$changes): array
    {
        $modules = array_unique(array_values($plan->controllerModules()));
        $aliases = [];

        try {
            foreach ($modules as $module) {
                $routeFile = $modulesPath.'/'.$module.'/Routes.php';
                $existing = $changes[$routeFile] ?? (File::exists($routeFile) ? File::get($routeFile) : null);

                if ($existing !== null) {
                    $aliases[$module] = ParsedFile::parse($existing)->blocks()[0]['aliases'];
                }
            }

            $code = File::get($apiPath);
            $result = (new ApiRoutesRelocator($rewriter, $plan->controllerModules()))->relocate($code, $aliases);
            $remaining = $rewriter->rewrite($result['remaining']);

            if ($remaining !== $code) {
                $changes[$apiPath] = $remaining;
            }

            $relocated = 0;

            foreach ($result['modules'] as $module => $routes) {
                $routeFile = $modulesPath.'/'.$module.'/Routes.php';
                $existing = $changes[$routeFile] ?? (File::exists($routeFile) ? File::get($routeFile) : null);
                $body = implode("\n\n", $routes['statements']);

                $changes[$routeFile] = $existing === null
                    ? (new ModuleGenerator)->routesFile(array_values($routes['imports']), $body)
                    : rtrim($rewriter->addImports($existing, $routes['imports']))."\n\n".$body."\n";

                $relocated += count($routes['statements']);
            }
        } catch (Error $error) {
            throw new RebuildException(sprintf('A route file cannot be parsed (%s), so its routes cannot be moved. Fix it first.', $error->getMessage()));
        }

        return [$relocated, $result['left']];
    }

    /**
     * Checks every computed file before anything is written.
     *
     * Each file must parse (token_get_all() with TOKEN_PARSE runs PHP's own parser), and each moved file
     * must declare its class in its new namespace.
     *
     * @param  array<string, string|null>  $changes  Path => new contents
     * @param  RebuildPlan  $plan  The plan
     *
     * @throws RebuildException When a file would not be valid or does not declare the planned class
     */
    private function assertValid(array $changes, RebuildPlan $plan): void
    {
        foreach ($changes as $path => $contents) {
            if ($contents === null) {
                continue;
            }

            try {
                $tokens = token_get_all($contents, TOKEN_PARSE);
            } catch (ParseError $error) {
                throw new RebuildException(sprintf('The rewritten %s would not be valid PHP (%s on line %d).', $path, $error->getMessage(), $error->getLine()));
            }

            if ($tokens === []) {
                throw new RebuildException(sprintf('The rewritten %s would be empty.', $path));
            }
        }

        $reader = new PhpClassReader;

        foreach ($plan->moves as $move) {
            $declaration = $reader->read((string) $changes[$move->destination]);

            if ($declaration === null || $declaration['namespace'] !== $move->namespace || $declaration['name'] !== $move->class) {
                throw new RebuildException(sprintf('The moved %s would not declare %s.', $move->destination, $move->newClass));
            }
        }
    }

    /**
     * Factory a moved model should name with #[UseFactory], when it has one by Laravel's naming.
     *
     * @param  Move  $move  Move of a model
     *
     * @return string|null Database\Factories\{Model}Factory when database/factories/{Model}Factory.php declares it, null otherwise
     */
    private function factoryFor(Move $move): ?string
    {
        $path = base_path('database/factories/'.$move->class.'Factory.php');
        $factory = 'Database\\Factories\\'.$move->class.'Factory';

        if (! File::exists($path) || ! in_array($factory, (new PhpClassReader)->classNames(File::get($path)), true)) {
            return null;
        }

        return $factory;
    }

    /**
     * PHP files whose class references are rewritten.
     *
     * Every *.php file under the REWRITTEN_FOLDERS that exist, except the generated caches in
     * bootstrap/cache.
     *
     * @return list<string> Absolute paths
     */
    private function phpFiles(): array
    {
        $paths = [];
        $cache = base_path('bootstrap/cache').DIRECTORY_SEPARATOR;

        foreach (self::REWRITTEN_FOLDERS as $folder) {
            $directory = $folder === 'app' ? app_path() : base_path($folder);

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'php' && ! str_starts_with($file->getPathname(), $cache)) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Tells whether source text names a moved class, with single or escaped backslashes.
     *
     * @param  string  $code  File contents
     * @param  RebuildPlan  $plan  The plan
     *
     * @return bool True when an old class name occurs
     */
    private function mentionsMovedClass(string $code, RebuildPlan $plan): bool
    {
        foreach ($plan->moves as $move) {
            if (stripos($code, $move->oldClass) !== false || stripos($code, str_replace('\\', '\\\\', $move->oldClass)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Places outside the rewritten PHP files that still name moved classes.
     *
     * Blade templates, JSON fixtures, YAML and other text files under the REPORTED_FOLDERS are searched
     * for the old names; they are reported, not changed.
     *
     * @param  RebuildPlan  $plan  The plan
     *
     * @return list<string> path:line entries
     */
    private function referencesOutsidePhp(RebuildPlan $plan): array
    {
        $patterns = [];

        foreach ($plan->moves as $move) {
            $patterns[] = preg_quote($move->oldClass, '/');
            $patterns[] = preg_quote(str_replace('\\', '\\\\', $move->oldClass), '/');
        }

        $regex = '/(?<![\w\\\\])(?:'.implode('|', $patterns).')(?![\w\\\\])/i';
        $found = [];

        foreach (self::REPORTED_FOLDERS as $folder) {
            if (! File::isDirectory(base_path($folder))) {
                continue;
            }

            foreach (File::allFiles(base_path($folder)) as $file) {
                $isPlainPhp = $file->getExtension() === 'php' && ! str_ends_with($file->getFilename(), '.blade.php');

                if (($isPlainPhp && $folder !== 'resources') || $file->getSize() > 1_048_576) {
                    continue;
                }

                foreach (preg_split('/\R/', File::get($file->getPathname())) ?: [] as $index => $line) {
                    if (preg_match($regex, $line) === 1) {
                        $found[] = sprintf('%s:%d', $file->getPathname(), $index + 1);
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Writes the journal, then every change through a FileTransaction.
     *
     * On a failure every file is put back, the journal is removed and the errors are printed.
     *
     * @param  array<string, string|null>  $changes  Path => new contents, null to delete
     *
     * @return bool True when every change was written
     */
    private function apply(array $changes): bool
    {
        try {
            $this->writeJournal($changes);
        } catch (Throwable $exception) {
            $this->stopWith('Could not write the journal: '.$exception->getMessage(), 'Nothing was changed.');

            return false;
        }

        $transaction = new FileTransaction;

        try {
            foreach ($changes as $path => $contents) {
                $contents === null ? $transaction->delete($path) : $transaction->put($path, $contents);
            }
        } catch (Throwable $exception) {
            $errors = $transaction->rollback();

            if ($errors === []) {
                File::delete($this->journalPath());
            }

            $this->stopWith(
                'consistent:rebuild failed: '.$exception->getMessage(),
                ...($errors === [] ? ['Every file was put back.'] : [...$errors, 'The journal is kept: restore the rest with consistent:rebuild --rollback --force.']),
            );

            return false;
        }

        return true;
    }

    /**
     * Saves what the run is about to change, so --rollback can undo it later.
     *
     * For every file: its contents before the run (base64, null when it did not exist) and a hash of its
     * contents after the run (null when it is deleted); plus the directories the run creates.
     *
     * @param  array<string, string|null>  $changes  Path => new contents, null to delete
     *
     * @throws JsonException When the journal cannot be encoded
     */
    private function writeJournal(array $changes): void
    {
        $files = [];
        $directories = [];

        foreach ($changes as $path => $contents) {
            $files[] = [
                'path' => $path,
                'before' => File::exists($path) ? base64_encode(File::get($path)) : null,
                'after' => $contents === null ? null : sha1($contents),
            ];

            for ($directory = dirname($path); ! File::isDirectory($directory) && dirname($directory) !== $directory; $directory = dirname($directory)) {
                $directories[$directory] = true;
            }
        }

        $directories = array_keys($directories);
        usort($directories, fn (string $left, string $right) => strlen($left) <=> strlen($right));

        File::ensureDirectoryExists(dirname($this->journalPath()));
        File::put($this->journalPath(), json_encode([
            'created_at' => date(DATE_ATOM),
            'files' => $files,
            'directories' => $directories,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Restores every file of the last run from its journal.
     *
     * A file changed since the run (it matches neither its contents before nor after the run) stops the
     * rollback, unless --force is given. Directories the run created are removed when empty.
     *
     * @return int Command::SUCCESS, or Command::FAILURE without a journal, on changed files or a failed write
     */
    private function rollback(): int
    {
        $path = $this->journalPath();

        if (! File::exists($path)) {
            return $this->stopWith(sprintf('There is nothing to roll back: %s does not exist.', $path));
        }

        $journal = json_decode(File::get($path), true);

        if (! is_array($journal) || ! is_array($journal['files'] ?? null)) {
            return $this->stopWith(sprintf('%s is not a consistent:rebuild journal.', $path));
        }

        $changed = [];

        foreach ($journal['files'] as $entry) {
            $current = File::isFile($entry['path']) ? sha1(File::get($entry['path'])) : null;
            $before = $entry['before'] === null ? null : sha1((string) base64_decode($entry['before'], true));

            if ($current !== $entry['after'] && $current !== $before) {
                $changed[] = $entry['path'];
            }
        }

        if ($changed !== [] && ! $this->option('force')) {
            return $this->stopWith(
                ...array_map(fn (string $file) => 'Changed since the rebuild: '.$file, $changed),
                ...['Nothing was restored. Run with --force to overwrite these changes.'],
            );
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Restore %d file(s) changed by the rebuild of %s?', count($journal['files']), $journal['created_at'] ?? 'unknown date'))) {
            $this->warn('Nothing was restored.');

            return Command::FAILURE;
        }

        $transaction = new FileTransaction;

        try {
            foreach ($journal['files'] as $entry) {
                $entry['before'] === null
                    ? $transaction->delete($entry['path'])
                    : $transaction->put($entry['path'], (string) base64_decode($entry['before'], true));
            }
        } catch (Throwable $exception) {
            return $this->stopWith('The rollback failed: '.$exception->getMessage(), ...$transaction->rollback());
        }

        foreach (array_reverse((array) ($journal['directories'] ?? [])) as $directory) {
            if (File::isDirectory($directory) && File::isEmptyDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        File::delete($path);
        $this->info(sprintf('Restored %d file(s).', count($journal['files'])));

        return Command::SUCCESS;
    }

    /**
     * Where the journal of the last run is kept.
     *
     * @return string Absolute path
     */
    private function journalPath(): string
    {
        return storage_path('app/consistent-rebuild.json');
    }

    /**
     * Prints what the run will do.
     *
     * @param  RebuildPlan  $plan  The plan
     * @param  array<string, string|null>  $changes  Path => new contents, null to delete
     * @param  int  $relocated  Number of route statements that move
     * @param  list<string>  $left  Route statements that name moved controllers but stay in routes/api.php
     */
    private function printPlan(RebuildPlan $plan, array $changes, int $relocated, array $left): void
    {
        $this->line('<comment>Classes to move:</comment>');

        foreach ($plan->moves as $move) {
            $this->line(sprintf('  %s → %s%s', $move->oldClass, $move->newClass, $move->note === null ? '' : ' ('.$move->note.')'));
        }

        $this->printKept($plan);

        if ($relocated > 0) {
            $this->line(sprintf('<comment>Route statements moving from routes/api.php:</comment> %d', $relocated));
        }

        if ($left !== []) {
            $this->line('<comment>Route statements left in routes/api.php (their class names are updated there):</comment>');

            foreach ($left as $statement) {
                $this->line('  '.$statement);
            }
        }

        $this->line(sprintf('<comment>Files to write:</comment> %d', count(array_filter($changes, fn (?string $contents) => $contents !== null))));

        if ($this->output->isVerbose()) {
            foreach ($changes as $path => $contents) {
                $this->line(sprintf('  %s %s', $contents === null ? 'delete' : 'write ', $path));
            }
        }

        $outside = $this->referencesOutsidePhp($plan);

        if ($outside !== []) {
            $this->warn('These files name moved classes and are not rewritten; update them by hand:');

            foreach (array_slice($outside, 0, 50) as $reference) {
                $this->line('  '.$reference);
            }

            if (count($outside) > 50) {
                $this->line(sprintf('  ... and %d more', count($outside) - 50));
            }
        }

        if (! $this->usesMorphMap()) {
            $this->warn('No morph map was found. Class names stored in the database (polymorphic *_type columns, notifications, '
                .'personal_access_tokens.tokenable_type, serialized queued jobs) keep the old names. Keep them working with a '
                .'morph map that maps the old names to the new classes, for example in AppServiceProvider::boot():');
            $this->line('  Relation::morphMap([');

            foreach ($plan->modelMoves() as $move) {
                $this->line(sprintf("      '%s' => \\%s::class,", $move->oldClass, $move->newClass));
            }

            $this->line('  ]);');
        }
    }

    /**
     * Prints the classes left in place and why.
     *
     * @param  RebuildPlan  $plan  The plan
     */
    private function printKept(RebuildPlan $plan): void
    {
        if ($plan->kept === []) {
            return;
        }

        $this->line('<comment>Left in place:</comment>');

        foreach ($plan->kept as $kept) {
            $this->line(sprintf('  %s: %s', $kept['class'], $kept['reason']));
        }
    }

    /**
     * Prints what to do after a successful run.
     *
     * @param  int  $relocated  Number of relocated route statements
     */
    private function printNextSteps(int $relocated): void
    {
        $this->line('<comment>Next steps:</comment>');
        $this->line('  - Review the changes (git diff) and run the tests.');
        $this->line('  - composer dump-autoload');
        $this->line('  - php artisan optimize:clear (cached config, routes and views still hold the old class names)');

        if ($relocated > 0) {
            $this->line(sprintf(
                '  - Moved routes now get the prefix "%s" and the middleware [%s] from config/consistentapi.php instead of bootstrap/app.php.',
                config('consistentapi.api_url_prefix', ''),
                implode(', ', (array) config('consistentapi.middlewares', [])),
            ));
        }

        $this->line('  - Undo the run with: php artisan consistent:rebuild --rollback');
    }

    /**
     * Tells whether the application defines a morph map.
     *
     * @return bool True when a PHP file of app/ calls morphMap() or enforceMorphMap()
     */
    private function usesMorphMap(): bool
    {
        if (! File::isDirectory(app_path())) {
            return false;
        }

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\b(?:enforceMorphMap|morphMap)\s*\(/', File::get($file->getPathname())) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warns when the git working tree of the project has uncommitted changes.
     *
     * A clean tree lets the run be reviewed with git diff and undone with git checkout.
     */
    private function warnAboutUncommittedChanges(): void
    {
        if (! File::isDirectory(base_path('.git'))) {
            return;
        }

        $status = new Process(['git', 'status', '--porcelain'], base_path());
        $status->run();

        if ($status->isSuccessful() && trim($status->getOutput()) !== '') {
            $this->warn('The git working tree has uncommitted changes. Commit or stash them first, so the rebuild can be reviewed and undone with git.');
        }
    }

    /**
     * Prints each message as an error and returns the failure code.
     *
     * @param  string  ...$messages  Messages to print
     *
     * @return int Command::FAILURE
     */
    private function stopWith(string ...$messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return Command::FAILURE;
    }
}
