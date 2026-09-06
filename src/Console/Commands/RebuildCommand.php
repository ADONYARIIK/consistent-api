<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class RebuildCommand extends Command
{
    protected $signature = 'consistent:rebuild';

    protected $description = 'Move conventional Laravel API classes into model modules';

    public function handle(): int
    {
        $modulesPath = app_path('Modules');
        File::ensureDirectoryExists($modulesPath);

        $models = $this->classesIn(app_path('Models'));

        if ($models === []) {
            $this->info('No models were found in app/Models.');

            return Command::SUCCESS;
        }

        $moves = [];

        foreach ($models as $model) {
            $destination = sprintf('%s/%s/Models/%s.php', $modulesPath, $model['class'], $model['class']);
            $moves[] = $this->move($model, $destination, sprintf('App\\Modules\\%s\\Models', $model['class']));
        }

        foreach ($this->componentDirectories() as $type => $directories) {
            foreach ($this->classesInDirectories($directories) as $component) {
                $model = $this->modelFor($component['class'], $models);

                if ($model === null) {
                    continue;
                }

                $destination = sprintf(
                    '%s/%s/%s/%s.php',
                    $modulesPath,
                    $model['class'],
                    $type,
                    $component['class'],
                );

                $moves[] = $this->move(
                    $component,
                    $destination,
                    sprintf('App\\Modules\\%s\\%s', $model['class'], $type),
                );
            }
        }

        $this->assertDestinationsAreAvailable($moves);

        foreach ($moves as $move) {
            File::ensureDirectoryExists(dirname($move['destination']));
            File::move($move['source'], $move['destination']);
            $this->replaceNamespace($move['destination'], $move['namespace']);
        }

        $replacements = [];

        foreach ($moves as $move) {
            $replacements[$move['oldClass']] = $move['newClass'];
        }

        $this->replaceImports($replacements);

        $this->info(sprintf('Moved %d class(es) into app/Modules.', count($moves)));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function componentDirectories(): array
    {
        return [
            'Controllers' => [app_path('Http/Controllers'), app_path('Controllers')],
            'Requests' => [app_path('Http/Requests'), app_path('Requests')],
            'Resources' => [app_path('Http/Resources'), app_path('Resources')],
        ];
    }

    /**
     * @return list<array{source: string, class: string, namespace: string, oldClass: string}>
     */
    private function classesIn(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classDetails($file);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param list<string> $directories
     * @return list<array{source: string, class: string, namespace: string, oldClass: string}>
     */
    private function classesInDirectories(array $directories): array
    {
        $classes = [];
        $seen = [];

        foreach ($directories as $directory) {
            foreach ($this->classesIn($directory) as $class) {
                if (isset($seen[$class['source']])) {
                    continue;
                }

                $seen[$class['source']] = true;
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array{source: string, class: string, namespace: string, oldClass: string}|null
     */
    private function classDetails(SplFileInfo $file): ?array
    {
        $contents = File::get($file->getPathname());
        $namespace = null;
        $class = null;
        $tokens = token_get_all($contents);

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->followingName($tokens, $index + 1);
            }

            if ($token[0] === T_CLASS) {
                $class = $this->followingName($tokens, $index + 1);
                break;
            }
        }

        if ($namespace === null || $class === null) {
            return null;
        }

        return [
            'source' => $file->getPathname(),
            'class' => $class,
            'namespace' => $namespace,
            'oldClass' => $namespace . '\\' . $class,
        ];
    }

    /**
     * @param list<array|string> $tokens
     */
    private function followingName(array $tokens, int $start): ?string
    {
        $name = '';

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
                continue;
            }

            if ($name !== '') {
                return trim($name, '\\');
            }
        }

        return null;
    }

    /**
     * @param list<array{source: string, class: string, namespace: string, oldClass: string}> $models
     * @return array{source: string, class: string, namespace: string, oldClass: string}|null
     */
    private function modelFor(string $class, array $models): ?array
    {
        $matches = array_values(array_filter(
            $models,
            fn(array $model): bool => str_contains(strtolower($class), strtolower($model['class'])),
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn(array $left, array $right): int => strlen($right['class']) <=> strlen($left['class']));

        return $matches[0];
    }

    /**
     * @param array{source: string, class: string, namespace: string, oldClass: string} $class
     * @return array{source: string, destination: string, namespace: string, oldClass: string, newClass: string}
     */
    private function move(array $class, string $destination, string $namespace): array
    {
        return [
            'source' => $class['source'],
            'destination' => $destination,
            'namespace' => $namespace,
            'oldClass' => $class['oldClass'],
            'newClass' => $namespace . '\\' . $class['class'],
        ];
    }

    /**
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string}> $moves
     */
    private function assertDestinationsAreAvailable(array $moves): void
    {
        $destinations = [];

        foreach ($moves as $move) {
            if (isset($destinations[$move['destination']])) {
                throw new \RuntimeException(sprintf('Several classes have the same destination: %s', $move['destination']));
            }

            if (File::exists($move['destination'])) {
                throw new \RuntimeException(sprintf('Destination already exists: %s', $move['destination']));
            }

            $destinations[$move['destination']] = true;
        }
    }

    private function replaceNamespace(string $path, string $namespace): void
    {
        $contents = File::get($path);
        $contents = preg_replace('/^(\\s*)namespace\\s+[^;]+;/m', '$1namespace ' . $namespace . ';', $contents, 1);

        File::put($path, $contents);
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replaceImports(array $replacements): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());
            $updated = $contents;

            foreach ($replacements as $oldClass => $newClass) {
                $updated = preg_replace(
                    '/^(\\s*use\\s+\\\\?)' . preg_quote($oldClass, '/') . '(\\s+(?:as\\s+\\w+)?)?\\s*;/mi',
                    '$1' . $newClass . '$2;',
                    $updated,
                );
            }

            if ($updated !== $contents) {
                File::put($file->getPathname(), $updated);
            }
        }
    }
}
