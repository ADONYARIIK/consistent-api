<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileInfo;
use Throwable;

class RebuildCommand extends Command
{
    protected $signature = 'consistent:rebuild';

    protected $description = 'Move conventional Laravel API classes into model modules';

    public function handle(): int
    {
        $modulesFolder = trim((string) config('consistentapi.modules_folder', 'Modules'), '/\\');
        $modulesPath = app_path($modulesFolder);
        $modulesNamespace = 'App\\' . str_replace('/', '\\', $modulesFolder);

        File::ensureDirectoryExists($modulesPath);

        $models = $this->classesIn(app_path('Models'));

        if ($models === []) {
            $this->info('No models were found in app/Models.');

            return Command::SUCCESS;
        }

        $moves = [];

        foreach ($models as $model) {
            $destination = sprintf('%s/%s/Models/%s.php', $modulesPath, $model['class'], $model['class']);
            $moves[] = $this->move(
                $model,
                $destination,
                sprintf('%s\\%s\\Models', $modulesNamespace, $model['class']),
            );
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
                    sprintf('%s\\%s\\%s', $modulesNamespace, $model['class'], $type),
                );
            }
        }

        $this->assertDestinationsAreAvailable($moves);

        $replacements = [];

        foreach ($moves as $move) {
            $replacements[$move['oldClass']] = $move['newClass'];
        }

        uksort($replacements, fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        $backups = [];
        $completedMoves = [];
        $createdFiles = [];

        try {
            foreach ($moves as $move) {
                $this->backupFile($move['source'], $backups);
                File::ensureDirectoryExists(dirname($move['destination']));
                File::move($move['source'], $move['destination']);
                $completedMoves[] = $move;
                $this->replaceNamespace($move['destination'], $move['namespace']);
            }

            $this->replaceClassReferences($replacements, $backups);

            $relocatedRoutes = $this->relocateApiRoutes(
                $moves,
                $modulesPath,
                $modulesNamespace,
                $replacements,
                $backups,
                $createdFiles,
            );

            $this->info(sprintf('Moved %d class(es) into app/%s.', count($moves), $modulesFolder));

            if ($relocatedRoutes > 0) {
                $this->info(sprintf('Relocated %d route statement(s) from routes/api.php into module Routes.php files.', $relocatedRoutes));
            }
        } catch (Throwable $exception) {
            $this->undoMoves(array_reverse($completedMoves));

            foreach ($createdFiles as $createdFile) {
                if (File::exists($createdFile)) {
                    File::delete($createdFile);
                }
            }

            $this->restoreBackups($backups, $completedMoves);

            throw $exception;
        }

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

            if ($token[0] === T_CLASS && $this->isClassDeclaration($tokens, $index)) {
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
    private function isClassDeclaration(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true)) {
                continue;
            }

            if ($token === '?') {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_DOUBLE_COLON, T_NEW], true)) {
                return false;
            }

            break;
        }

        $name = $this->followingName($tokens, $index + 1);

        return $name !== null && $name !== '';
    }

    /**
     * @param list<array|string> $tokens
     */
    private function followingName(array $tokens, int $start): ?string
    {
        $name = '';

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
                continue;
            }

            if (is_array($token) && $token[0] === T_WHITESPACE && $name === '') {
                continue;
            }

            if ($name !== '') {
                return trim($name, '\\');
            }

            return null;
        }

        return $name !== '' ? trim($name, '\\') : null;
    }

    /**
     * @param list<array{source: string, class: string, namespace: string, oldClass: string}> $models
     * @return array{source: string, class: string, namespace: string, oldClass: string}|null
     */
    private function modelFor(string $class, array $models): ?array
    {
        $matches = array_values(array_filter(
            $models,
            fn(array $model): bool => $this->classBelongsToModel($class, $model['class']),
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn(array $left, array $right): int => strlen($right['class']) <=> strlen($left['class']));

        return $matches[0];
    }

    private function classBelongsToModel(string $class, string $model): bool
    {
        if ($class === $model) {
            return true;
        }

        return (bool) preg_match(
            '/(?:^|(?<=[a-z0-9]))' . preg_quote($model, '/') . '(?=[A-Z]|$)/',
            $class,
        );
    }

    /**
     * @param array{source: string, class: string, namespace: string, oldClass: string} $class
     * @return array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}
     */
    private function move(array $class, string $destination, string $namespace): array
    {
        return [
            'source' => $class['source'],
            'destination' => $destination,
            'namespace' => $namespace,
            'oldClass' => $class['oldClass'],
            'newClass' => $namespace . '\\' . $class['class'],
            'class' => $class['class'],
        ];
    }

    /**
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}> $moves
     */
    private function assertDestinationsAreAvailable(array $moves): void
    {
        $destinations = [];

        foreach ($moves as $move) {
            if (isset($destinations[$move['destination']])) {
                throw new RuntimeException(sprintf('Several classes have the same destination: %s', $move['destination']));
            }

            if (File::exists($move['destination'])) {
                throw new RuntimeException(sprintf('Destination already exists: %s', $move['destination']));
            }

            $destinations[$move['destination']] = true;
        }
    }

    private function replaceNamespace(string $path, string $namespace): void
    {
        $contents = File::get($path);
        $updated = preg_replace('/^(\\s*)namespace\\s+[^;]+;/m', '$1namespace ' . $namespace . ';', $contents, 1);

        if (! is_string($updated)) {
            throw new RuntimeException(sprintf('Failed to rewrite namespace in %s', $path));
        }

        File::put($path, $updated);
    }

    /**
     * @param array<string, string> $replacements
     * @param array<string, string> $backups
     */
    private function replaceClassReferences(array $replacements, array &$backups): void
    {
        foreach ($this->phpFilesForReferenceRewrite() as $path) {
            $contents = File::get($path);
            $updated = $this->applyClassReplacements($contents, $replacements);

            if ($updated !== $contents) {
                $this->backupFile($path, $backups);
                File::put($path, $updated);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesForReferenceRewrite(): array
    {
        $paths = [];

        foreach ([app_path(), base_path('routes'), base_path('database'), base_path('tests')] as $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function applyClassReplacements(string $contents, array $replacements): string
    {
        $updated = $contents;

        foreach ($replacements as $oldClass => $newClass) {
            $replacedUses = preg_replace(
                '/^(\\s*use\\s+\\\\?)' . preg_quote($oldClass, '/') . '(\\s+(?:as\\s+\\w+)?)?\\s*;/mi',
                '$1' . $newClass . '$2;',
                $updated,
            );

            if (! is_string($replacedUses)) {
                throw new RuntimeException(sprintf('Failed to rewrite use statement for %s', $oldClass));
            }

            $updated = $replacedUses;

            $replacedFqcn = preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote($oldClass, '/') . '(?![A-Za-z0-9_])/',
                $newClass,
                $updated,
            );

            if (! is_string($replacedFqcn)) {
                throw new RuntimeException(sprintf('Failed to rewrite class reference for %s', $oldClass));
            }

            $updated = $replacedFqcn;
        }

        return $updated;
    }

    /**
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}> $moves
     * @param array<string, string> $replacements
     * @param array<string, string> $backups
     * @param list<string> $createdFiles
     */
    private function relocateApiRoutes(
        array $moves,
        string $modulesPath,
        string $modulesNamespace,
        array $replacements,
        array &$backups,
        array &$createdFiles,
    ): int {
        $apiPath = base_path('routes/api.php');

        if (! File::exists($apiPath)) {
            return 0;
        }

        $controllersByModule = [];

        foreach ($moves as $move) {
            if (! str_contains($move['destination'], DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR)
                && ! str_contains($move['destination'], '/Controllers/')) {
                continue;
            }

            if (! preg_match('/^' . preg_quote($modulesNamespace, '/') . '\\\\([^\\\\]+)\\\\/', $move['newClass'], $matches)) {
                continue;
            }

            $module = $matches[1];
            $controllersByModule[$module][] = $move;
        }

        if ($controllersByModule === []) {
            return 0;
        }

        $contents = File::get($apiPath);
        $backups[$apiPath] = $contents;
        $remaining = $contents;
        $relocated = 0;

        foreach ($controllersByModule as $module => $controllerMoves) {
            $needles = [];

            foreach ($controllerMoves as $move) {
                $needles[] = $move['oldClass'];
                $needles[] = $move['newClass'];
                $needles[] = $move['class'];
            }

            $needles = array_values(array_unique($needles));
            [$statements, $remaining] = $this->extractMatchingRouteStatements($remaining, $needles);

            if ($statements === []) {
                continue;
            }

            $routeFile = $modulesPath . '/' . $module . '/Routes.php';

            if (File::exists($routeFile)) {
                $this->backupFile($routeFile, $backups);
            } else {
                $createdFiles[] = $routeFile;
            }

            $this->writeModuleRoutes($routeFile, $statements, $controllerMoves, $replacements);
            $relocated += count($statements);
        }

        if ($relocated > 0) {
            $controllerClasses = [];

            foreach ($controllersByModule as $controllerMoves) {
                foreach ($controllerMoves as $move) {
                    $controllerClasses[] = $move['oldClass'];
                    $controllerClasses[] = $move['newClass'];
                }
            }

            $remaining = $this->removeUseStatements($remaining, array_values(array_unique($controllerClasses)));
            File::put($apiPath, $this->normalizePhpFile($remaining));
        }

        return $relocated;
    }

    /**
     * @param list<string> $classes
     */
    private function removeUseStatements(string $contents, array $classes): string
    {
        foreach ($classes as $class) {
            $updated = preg_replace(
                '/^\\s*use\\s+\\\\?' . preg_quote($class, '/') . '(?:\\s+as\\s+\\w+)?\\s*;\\s*$\\n?/mi',
                '',
                $contents,
            );

            if (is_string($updated)) {
                $contents = $updated;
            }
        }

        return $contents;
    }

    /**
     * @param list<string> $needles
     * @return array{0: list<string>, 1: string}
     */
    private function extractMatchingRouteStatements(string $contents, array $needles): array
    {
        $statements = [];
        $offset = 0;
        $length = strlen($contents);
        $remainingParts = [];
        $cursor = 0;

        while ($offset < $length) {
            $start = strpos($contents, 'Route::', $offset);

            if ($start === false) {
                break;
            }

            $statement = $this->readPhpStatement($contents, $start);

            if ($statement === null) {
                $offset = $start + 7;
                continue;
            }

            $end = $start + strlen($statement);

            if ($this->statementReferencesAny($statement, $needles)) {
                $remainingParts[] = substr($contents, $cursor, $start - $cursor);
                $statements[] = trim($statement);
                $cursor = $end;
            }

            $offset = $end;
        }

        $remainingParts[] = substr($contents, $cursor);

        return [$statements, implode('', $remainingParts)];
    }

    private function readPhpStatement(string $contents, int $start): ?string
    {
        $length = strlen($contents);
        $index = $start;
        $paren = 0;
        $brace = 0;
        $bracket = 0;
        $string = null;
        $escaped = false;

        while ($index < $length) {
            $char = $contents[$index];

            if ($string !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $string) {
                    $string = null;
                }

                $index++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $string = $char;
                $index++;
                continue;
            }

            if ($char === '(') {
                $paren++;
            } elseif ($char === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($char === '{') {
                $brace++;
            } elseif ($char === '}') {
                $brace = max(0, $brace - 1);
            } elseif ($char === '[') {
                $bracket++;
            } elseif ($char === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($char === ';' && $paren === 0 && $brace === 0 && $bracket === 0) {
                return substr($contents, $start, $index - $start + 1);
            }

            $index++;
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function statementReferencesAny(string $statement, array $needles): bool
    {
        usort($needles, fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($needles as $needle) {
            if (str_contains($needle, '\\')) {
                if (str_contains($statement, $needle)) {
                    return true;
                }

                continue;
            }

            if (preg_match('/(?<![A-Za-z0-9_\\\\])' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $statements
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}> $controllerMoves
     * @param array<string, string> $replacements
     */
    private function writeModuleRoutes(
        string $routeFile,
        array $statements,
        array $controllerMoves,
        array $replacements,
    ): void {
        File::ensureDirectoryExists(dirname($routeFile));

        $body = implode("\n\n", array_map(
            fn(string $statement): string => rtrim($this->applyClassReplacements($statement, $replacements), "\n"),
            $statements,
        ));

        $uses = [
            'Illuminate\\Support\\Facades\\Route',
        ];

        foreach ($controllerMoves as $move) {
            $uses[] = $move['newClass'];
        }

        if (File::exists($routeFile)) {
            $existing = File::get($routeFile);
            $existing = $this->ensureUseStatements($existing, $uses);
            $existing = rtrim($existing) . "\n\n" . $body . "\n";
            File::put($routeFile, $existing);

            return;
        }

        $useBlock = implode("\n", array_map(
            fn(string $class): string => 'use ' . $class . ';',
            array_values(array_unique($uses)),
        ));

        $contents = "<?php\n\ndeclare(strict_types=1);\n\n{$useBlock}\n\n{$body}\n";
        File::put($routeFile, $contents);
    }

    /**
     * @param list<string> $classes
     */
    private function ensureUseStatements(string $contents, array $classes): string
    {
        foreach (array_values(array_unique($classes)) as $class) {
            $pattern = '/^\\s*use\\s+\\\\?' . preg_quote($class, '/') . '\\s*;/m';

            if (preg_match($pattern, $contents) === 1) {
                continue;
            }

            if (preg_match('/^declare\\s*\\(strict_types\\s*=\\s*1\\)\\s*;\\s*$/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $insertAt = $match[0][1] + strlen($match[0][0]);
                $contents = substr($contents, 0, $insertAt) . "\n\nuse {$class};" . substr($contents, $insertAt);
                continue;
            }

            if (preg_match('/^<\\?php\\s*/', $contents, $match) === 1) {
                $contents = preg_replace('/^<\\?php\\s*/', "<?php\n\nuse {$class};\n\n", $contents, 1) ?? $contents;
                continue;
            }

            $contents = "<?php\n\nuse {$class};\n\n" . $contents;
        }

        return $contents;
    }

    private function normalizePhpFile(string $contents): string
    {
        $normalized = preg_replace("/\n{3,}/", "\n\n", $contents);

        if (! is_string($normalized)) {
            $normalized = $contents;
        }

        $normalized = trim($normalized);

        if ($normalized === '' || $normalized === '<?php') {
            return "<?php\n";
        }

        if (! str_starts_with($normalized, '<?php')) {
            $normalized = "<?php\n\n" . $normalized;
        }

        return rtrim($normalized) . "\n";
    }

    /**
     * @param array<string, string> $backups
     */
    private function backupFile(string $path, array &$backups): void
    {
        if (! isset($backups[$path]) && File::exists($path)) {
            $backups[$path] = File::get($path);
        }
    }

    /**
     * @param array<string, string> $backups
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}> $completedMoves
     */
    private function restoreBackups(array $backups, array $completedMoves = []): void
    {
        $destinations = [];

        foreach ($completedMoves as $move) {
            $destinations[$move['destination']] = true;
        }

        foreach ($backups as $path => $contents) {
            if (isset($destinations[$path])) {
                continue;
            }

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }
    }

    /**
     * @param list<array{source: string, destination: string, namespace: string, oldClass: string, newClass: string, class: string}> $moves
     */
    private function undoMoves(array $moves): void
    {
        foreach ($moves as $move) {
            if (File::exists($move['destination'])) {
                File::delete($move['destination']);
            }
        }
    }
}
