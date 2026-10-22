<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands;

use Adonyarik\ConsistentApi\Console\Commands\Concerns\ResolvesModulesFolder;
use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprint;
use Adonyarik\ConsistentApi\Console\Generators\ModuleBlueprintFactory;
use Adonyarik\ConsistentApi\Console\Generators\ModuleGenerator;
use Adonyarik\ConsistentApi\Console\Support\FileTransaction;
use Adonyarik\ConsistentApi\Exceptions\InvalidModuleOptionsException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Artisan command consistent:create: generates an API module.
 *
 * Turns the model name and the --routes, --accesses, --resources and --uuid options into a ModuleBlueprint,
 * renders the module files with ModuleGenerator and writes them under app/{modules_folder}/{Module}: model,
 * controller, form requests, resources and Routes.php. Invalid options, and target files that already
 * exist when --force is not given, are reported as errors before anything is written. The files are
 * written all or nothing.
 *
 * @package Adonyarik\ConsistentApi\Console\Commands
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class CreateModuleCommand extends Command
{
    use ResolvesModulesFolder;

    /**
     * Command name, the model argument and the options; repeated and comma-separated values are both accepted.
     *
     * @var string
     */
    protected $signature = 'consistent:create
        {model : The model name (e.g. Post)}
        {--routes=* : get, post, put, patch, delete, all (default: all)}
        {--accesses=* : method-access pairs from consistentapi.accesses, e.g. all-verified,delete-admin (default: none)}
        {--resources=* : l|light, s|standard, a|admin, all (default: standard)}
        {--uuid : Use the consistentapi.uuid_column UUID as the route key}
        {--force : Overwrite existing files}';

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
        $this->description = sprintf('Create an API module under app/%s', $this->modulesFolder());

        parent::__construct();
    }

    /**
     * Generates the module.
     *
     * Builds the blueprint from the arguments and config (modules folder, application namespace, access
     * groups, UUID settings) and renders every file. Refuses to overwrite existing files without --force,
     * and, even with --force, to put a second model into a module that already holds another one (Datum
     * and Data share the module Data). Then writes the files through a FileTransaction: when a write
     * fails, the files written so far are put back and the command fails. With --force, files of an earlier
     * run that this run does not generate are listed, not deleted. With --uuid it also prints the migration
     * line for the UUID column.
     *
     * @return int Command::SUCCESS, or Command::FAILURE on invalid options, existing files or a failed write
     *
     * @see ModuleBlueprintFactory::make()
     * @see ModuleGenerator::generate()
     * @see FileTransaction
     */
    public function handle(): int
    {
        $factory = new ModuleBlueprintFactory(
            app_path(),
            (string) config('consistentapi.modules_folder', 'Modules'),
            (array) config('consistentapi.accesses', []),
            (bool) config('consistentapi.uuid', false),
            (string) config('consistentapi.uuid_column', ''),
            $this->rootNamespace(),
        );

        try {
            $blueprint = $factory->make(
                (string) $this->argument('model'),
                (array) $this->option('routes'),
                (array) $this->option('accesses'),
                (array) $this->option('resources'),
                (bool) $this->option('uuid'),
            );
        } catch (InvalidModuleOptionsException $e) {
            return $this->reportErrors($e->errors);
        }

        $files = (new ModuleGenerator)->generate($blueprint);

        $otherModels = $this->otherModels($blueprint);

        if ($otherModels !== []) {
            return $this->reportErrors([sprintf(
                'Module %s already holds the model %s; %s would share its Routes.php. Choose another model name.',
                $blueprint->module,
                implode(', ', $otherModels),
                $blueprint->model,
            )]);
        }

        if (! $this->option('force')) {
            $existing = array_filter(array_keys($files), fn (string $path) => File::exists($path));

            if ($existing !== []) {
                return $this->reportErrors(array_map(
                    fn (string $path) => sprintf('File already exists: %s (use --force to overwrite)', $path),
                    array_values($existing),
                ));
            }
        }

        $transaction = new FileTransaction;

        try {
            foreach ($files as $path => $contents) {
                $transaction->put($path, $contents);
            }
        } catch (Throwable $exception) {
            $errors = $transaction->rollback();

            return $this->reportErrors([sprintf('Could not write the module: %s Nothing was changed.', $exception->getMessage()), ...$errors]);
        }

        foreach (array_keys($files) as $path) {
            $this->line('Created: '.$path);
        }

        $this->info(sprintf('Module %s created successfully.', $blueprint->module));

        foreach ($this->leftovers($blueprint, $files) as $leftover) {
            $this->warn(sprintf('Not generated by this run, left in place: %s', $leftover));
        }

        if ($blueprint->uuidColumn !== null) {
            $this->line(sprintf(
                'Add the UUID column to the %s migration: $table->uuid(\'%s\')->unique();',
                $blueprint->table,
                $blueprint->uuidColumn,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Models other than the blueprint's that the module folder already holds.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     *
     * @return list<string> Short class names, from the file names in Models/
     */
    private function otherModels(ModuleBlueprint $blueprint): array
    {
        $models = [];

        foreach (File::glob($blueprint->path.'/Models/*.php') as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            if ($name !== $blueprint->model) {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * PHP files of the module that this run does not generate.
     *
     * Controllers, requests and resources an earlier run with wider options generated, for example; they
     * may hold code of the developer, so they are reported, not deleted.
     *
     * @param  ModuleBlueprint  $blueprint  Validated module options
     * @param  array<string, string>  $files  Generated files by path
     *
     * @return list<string> Paths of the files left in place
     */
    private function leftovers(ModuleBlueprint $blueprint, array $files): array
    {
        $leftovers = [];

        foreach (['Controllers', 'Requests', 'Resources'] as $folder) {
            foreach (File::glob($blueprint->path.'/'.$folder.'/*.php') as $file) {
                if (! array_key_exists($file, $files)) {
                    $leftovers[] = $file;
                }
            }
        }

        return $leftovers;
    }

    /**
     * Prints each error and returns the failure code.
     *
     * Used for option errors, existing files and failed writes, so all problems are shown at once.
     *
     * @param  list<string>  $errors  Messages to print
     *
     * @return int Command::FAILURE
     */
    private function reportErrors(array $errors): int
    {
        foreach ($errors as $error) {
            $this->error($error);
        }

        return Command::FAILURE;
    }
}
