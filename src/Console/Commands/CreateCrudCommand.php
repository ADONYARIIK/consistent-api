<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CreateCrudCommand extends Command
{
    protected $signature = 'consistent:crud {model : The model name (e.g. Post)} {--force : Overwrite existing files}';

    protected $description = 'Create a full CRUD module under app/Modules';

    public function handle(): int
    {
        $modelName = Str::studly((string) $this->argument('model'));

        if ($modelName === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $modelName)) {
            $this->error('Model name must be a valid StudlyCase class name (e.g. Post).');

            return Command::FAILURE;
        }

        $moduleName = Str::pluralStudly($modelName);
        $modulesFolder = trim((string) config('consistentapi.modules_folder', 'Modules'), '/\\');
        $modulesPath = app_path($modulesFolder);
        $modulePath = $modulesPath . '/' . $moduleName;
        $namespace = 'App\\' . str_replace('/', '\\', $modulesFolder) . '\\' . $moduleName;
        $table = Str::snake($moduleName);
        $route = $table;
        $param = Str::snake($modelName);
        $force = (bool) $this->option('force');

        $replacements = [
            '{{ model }}' => $modelName,
            '{{ module }}' => $moduleName,
            '{{ namespace }}' => $namespace,
            '{{ table }}' => $table,
            '{{ route }}' => $route,
            '{{ param }}' => $param,
        ];

        $files = [
            $modulePath . '/Models/' . $modelName . '.php' => 'model.stub',
            $modulePath . '/Controllers/' . $modelName . 'Controller.php' => 'controller.stub',
            $modulePath . '/Requests/' . $modelName . 'SearchRequest.php' => 'search-request.stub',
            $modulePath . '/Requests/Store' . $modelName . 'Request.php' => 'store-request.stub',
            $modulePath . '/Requests/Update' . $modelName . 'Request.php' => 'update-request.stub',
            $modulePath . '/Resources/' . $modelName . 'Resource.php' => 'resource.stub',
            $modulePath . '/Routes.php' => 'routes.stub',
        ];

        if (! $force) {
            foreach (array_keys($files) as $path) {
                if (File::exists($path)) {
                    $this->error(sprintf('File already exists: %s (use --force to overwrite)', $path));

                    return Command::FAILURE;
                }
            }
        }

        foreach ($files as $path => $stub) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $this->renderStub($stub, $replacements));
            $this->line('Created: ' . $path);
        }

        $this->info(sprintf('CRUD module %s created successfully.', $moduleName));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function renderStub(string $stub, array $replacements): string
    {
        $path = dirname(__DIR__, 3) . '/stubs/crud/' . $stub;

        if (! File::exists($path)) {
            throw new RuntimeException(sprintf('Stub not found: %s', $path));
        }

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            File::get($path),
        );
    }
}
