<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use Adonyarik\ConsistentApi\Support\PhpClassReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpParser\Error;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Throwable;

/**
 * Decides which classes consistent:rebuild moves where, before any file changes.
 *
 * Every concrete Eloquent model in app/Models (a class extending Model, Authenticatable, Pivot or
 * ConsistentModel, directly or through other classes) becomes the model of the module {PluralModel}.
 * Abstract classes, scopes, casts and other classes that are not models stay in place, and so does any
 * file that declares several classes. Controllers, form requests and resources from
 * app/Http/{Controllers,Requests,Resources} and app/{Controllers,Requests,Resources} follow the model their
 * name points to: the type suffix (Controller, Request, Resource, Collection), an action prefix (Store,
 * Update, Search, ...) and a variant suffix (Light, Admin, ...) are taken off and the rest must equal a
 * model name, in the singular or the plural. A name that points to several models (AdminPostController
 * with models Admin and Post) stays in place and is reported, so nothing is guessed.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class RebuildPlanner
{
    /**
     * Lowercased classes every Eloquent model descends from, or is.
     */
    private const MODEL_BASES = [
        'illuminate\\database\\eloquent\\model',
        'illuminate\\foundation\\auth\\user',
        'illuminate\\database\\eloquent\\relations\\pivot',
        'illuminate\\database\\eloquent\\relations\\morphpivot',
        'adonyarik\\consistentapi\\models\\consistentmodel',
    ];

    /**
     * Class name suffixes of each component type.
     */
    private const TYPE_SUFFIXES = [
        'Controllers' => ['Controller'],
        'Requests' => ['Request'],
        'Resources' => ['Resource', 'Collection'],
    ];

    /**
     * Action words that may start a component name, as in StorePostRequest.
     */
    private const ACTION_PREFIXES = ['Store', 'Update', 'Search', 'Index', 'Show', 'Create', 'Edit', 'Destroy', 'Delete', 'List', 'Filter', 'Upsert', 'Save'];

    /**
     * Variant words that may end a component name before its suffix, as in PostLightResource.
     */
    private const VARIANT_SUFFIXES = ['Light', 'Admin', 'Full', 'Short', 'Simple', 'Detail', 'Details', 'Public', 'Private'];

    /**
     * Takes the folders to read and the modules folder to plan into.
     *
     * @param  string  $appPath  Absolute path of app/
     * @param  string  $modulesPath  Absolute path of app/{modules_folder}
     * @param  string  $modulesNamespace  Namespace of the modules folder, such as App\Modules
     * @param  Filesystem  $files  Laravel's filesystem
     */
    public function __construct(
        private readonly string $appPath,
        private readonly string $modulesPath,
        private readonly string $modulesNamespace,
        private readonly Filesystem $files = new Filesystem,
    ) {}

    /**
     * Folders to collect controllers, requests and resources from, per module subfolder.
     *
     * The Laravel default under app/Http and the flat alternative under app/.
     *
     * @return array<string, list<string>> Module subfolder => source folders
     */
    public function componentDirectories(): array
    {
        return [
            'Controllers' => [$this->appPath.'/Http/Controllers', $this->appPath.'/Controllers'],
            'Requests' => [$this->appPath.'/Http/Requests', $this->appPath.'/Requests'],
            'Resources' => [$this->appPath.'/Http/Resources', $this->appPath.'/Resources'],
        ];
    }

    /**
     * Plans the run.
     *
     * Lists the moves, the classes left in place with their reasons, and the errors that stop the run:
     * two classes with one destination, or a destination that exists already.
     *
     * @return RebuildPlan The plan
     */
    public function plan(): RebuildPlan
    {
        $moves = [];
        $kept = [];
        $models = [];
        $modelFiles = $this->scan([$this->appPath.'/Models']);
        $parents = [];
        $knownClasses = [];

        foreach ($modelFiles as $entry) {
            if ($entry['class'] !== null) {
                $parents[strtolower($entry['class']['fqcn'])] = $entry['class']['parent'];
            }
        }

        foreach ($modelFiles as $entry) {
            $knownClasses = [...$knownClasses, ...$entry['names']];

            if ($this->keptForShape($entry, $kept)) {
                continue;
            }

            $class = $entry['class'];

            if ($class['abstract']) {
                $kept[] = ['class' => $class['fqcn'], 'reason' => 'abstract class'];

                continue;
            }

            if (! $this->isModel($class['fqcn'], $parents)) {
                $kept[] = ['class' => $class['fqcn'], 'reason' => 'not an Eloquent model'];

                continue;
            }

            $module = Str::pluralStudly($class['name']);

            if ($module === 'Middleware') {
                $kept[] = ['class' => $class['fqcn'], 'reason' => 'its module would be Middleware, a folder whose routes are never loaded'];

                continue;
            }

            $models[] = $class['name'];
            $moves[] = $this->move($entry['source'], $class, 'Models', $module);
        }

        foreach ($this->componentDirectories() as $type => $directories) {
            foreach ($this->scan($directories) as $entry) {
                $knownClasses = [...$knownClasses, ...$entry['names']];

                if ($this->keptForShape($entry, $kept) || $entry['class']['abstract']) {
                    continue;
                }

                $class = $entry['class'];
                [$model, $note, $candidates] = $this->modelFor($class['name'], $type, $models);

                if ($candidates !== []) {
                    $kept[] = ['class' => $class['fqcn'], 'reason' => sprintf('its name matches several models (%s); move it by hand', implode(', ', $candidates))];

                    continue;
                }

                if ($model === null) {
                    $kept[] = ['class' => $class['fqcn'], 'reason' => 'no model matches its name'];

                    continue;
                }

                $moves[] = $this->move($entry['source'], $class, $type, Str::pluralStudly($model), $note);
            }
        }

        return new RebuildPlan($moves, $kept, $this->conflicts($moves), array_values(array_unique($knownClasses)), $modelFiles !== []);
    }

    /**
     * Tells whether a file is left alone for its shape, reporting it when it declares several classes.
     *
     * Files without a single class in a named namespace (interfaces, traits, enums, functions, files
     * outside any namespace) are skipped silently.
     *
     * @param  array{source: string, names: list<string>, count: int, class: array{fqcn: string, name: string, namespace: string, abstract: bool, parent: string|null}|null}  $entry  Scanned file
     * @param  list<array{class: string, reason: string}>  $kept  Classes left in place, filled in
     *
     * @return bool True when the file does not move
     */
    private function keptForShape(array $entry, array &$kept): bool
    {
        if ($entry['count'] > 1) {
            foreach ($entry['names'] as $name) {
                $kept[] = ['class' => $name, 'reason' => 'its file declares several classes; split it or move it by hand'];
            }

            return true;
        }

        return $entry['class'] === null;
    }

    /**
     * Reads the PHP files of the folders, recursively, each file once.
     *
     * @param  list<string>  $directories  Folders to read; missing ones give nothing
     *
     * @return list<array{source: string, names: list<string>, count: int, class: array{fqcn: string, name: string, namespace: string, abstract: bool, parent: string|null}|null}> Per file: its declarations and, when it declares exactly one class in a named namespace, that class
     */
    private function scan(array $directories): array
    {
        $reader = new PhpClassReader;
        $entries = [];
        $seen = [];

        foreach ($directories as $directory) {
            if (! $this->files->isDirectory($directory)) {
                continue;
            }

            foreach ($this->files->allFiles($directory) as $file) {
                $path = $file->getPathname();

                if ($file->getExtension() !== 'php' || isset($seen[$path])) {
                    continue;
                }

                $seen[$path] = true;
                $source = $this->files->get($path);
                $declarations = $reader->readAll($source);
                $names = array_map(fn (array $declaration) => ltrim($declaration['namespace'].'\\'.$declaration['name'], '\\'), $declarations);
                $class = null;

                if (count($declarations) === 1 && $declarations[0]['kind'] === 'class' && $declarations[0]['namespace'] !== '') {
                    $class = $this->describeClass($source, $declarations[0]['namespace'], $declarations[0]['name']);
                }

                $entries[] = ['source' => $path, 'names' => $names, 'count' => count($declarations), 'class' => $class];
            }
        }

        return $entries;
    }

    /**
     * Whether a class is abstract and what it extends, read from its syntax tree.
     *
     * @param  string  $source  PHP source declaring the class
     * @param  string  $namespace  Namespace of the class
     * @param  string  $name  Short class name
     *
     * @return array{fqcn: string, name: string, namespace: string, abstract: bool, parent: string|null}|null The class, null when the file cannot be parsed
     */
    private function describeClass(string $source, string $namespace, string $name): ?array
    {
        try {
            $file = ParsedFile::parse($source);
        } catch (Error) {
            return null;
        }

        foreach ($file->blocks() as $block) {
            foreach ($block['stmts'] as $stmt) {
                if ($stmt instanceof Stmt\Class_ && $stmt->name?->toString() === $name) {
                    $parent = $stmt->extends?->getAttribute('resolvedName');

                    return [
                        'fqcn' => $namespace.'\\'.$name,
                        'name' => $name,
                        'namespace' => $namespace,
                        'abstract' => $stmt->isAbstract(),
                        'parent' => $parent instanceof Name ? $parent->toString() : null,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Tells whether a class is an Eloquent model.
     *
     * Classes of app/Models are followed through their parents in the source; other classes are loaded
     * (vendor models, for example) and checked with is_a().
     *
     * @param  string  $class  Fully qualified class name
     * @param  array<string, string|null>  $parents  Lowercased classes of app/Models => their parent class
     * @param  array<string, true>  $seen  Classes visited, against cycles
     *
     * @return bool True for a class descending from Model
     */
    private function isModel(string $class, array $parents, array $seen = []): bool
    {
        $key = strtolower($class);

        if (in_array($key, self::MODEL_BASES, true)) {
            return true;
        }

        if (isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        if (array_key_exists($key, $parents)) {
            return $parents[$key] !== null && $this->isModel($parents[$key], $parents, $seen);
        }

        try {
            return class_exists($class) && is_a($class, Model::class, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Model a component class belongs to.
     *
     * Tries, in this order, the name without its type suffix, then without an action prefix, then without
     * a variant suffix, then the singular of those; the first try that matches decides. When no try
     * matches exactly, a model whose name is a StudlyCase word of the class name is taken if it is the only
     * one (V2PostResource goes with Post), or if it contains all the others (PostCommentReplyController
     * goes with PostComment rather than Post).
     *
     * @param  string  $class  Short class name of a controller, request or resource
     * @param  string  $type  Controllers, Requests or Resources
     * @param  list<string>  $models  Short names of the models that move
     *
     * @return array{0: string|null, 1: string|null, 2: list<string>} The model or null, a note when the match was not exact, and the models of an ambiguous name
     */
    private function modelFor(string $class, string $type, array $models): array
    {
        $core = $class;

        foreach (self::TYPE_SUFFIXES[$type] as $suffix) {
            if (str_ends_with($core, $suffix) && $core !== $suffix) {
                $core = substr($core, 0, -strlen($suffix));

                break;
            }
        }

        $withoutAction = $this->stripWord($core, self::ACTION_PREFIXES, prefix: true);
        $stages = [[$core]];

        if ($withoutAction !== null) {
            $stages[] = [$withoutAction];
        }

        $variants = array_values(array_filter(array_map(
            fn (string $name) => $this->stripWord($name, self::VARIANT_SUFFIXES, prefix: false),
            array_merge(...$stages),
        )));

        if ($variants !== []) {
            $stages[] = $variants;
        }

        $stages[] = array_map(fn (string $name) => Str::singular($name), array_merge(...$stages));

        foreach ($stages as $candidates) {
            $matches = array_values(array_unique(array_intersect($models, $candidates)));

            if (count($matches) === 1) {
                return [$matches[0], null, []];
            }

            if ($matches !== []) {
                return [null, null, $matches];
            }
        }

        $matches = array_values(array_filter($models, fn (string $model) => $this->containsWord($class, $model)));

        foreach ($matches as $match) {
            $notContained = array_filter($matches, fn (string $other) => ! str_contains($match, $other));

            if ($notContained === []) {
                return [$match, sprintf('matched by the "%s" part of its name', $match), []];
            }
        }

        return [null, null, $matches];
    }

    /**
     * Name without one of the words at its start or end, when the rest is a StudlyCase name.
     *
     * @param  string  $name  StudlyCase name
     * @param  list<string>  $words  Words to take off
     * @param  bool  $prefix  True to look at the start, false at the end
     *
     * @return string|null The rest, null when no word matches
     */
    private function stripWord(string $name, array $words, bool $prefix): ?string
    {
        foreach ($words as $word) {
            if ($prefix && str_starts_with($name, $word) && preg_match('/^[A-Z]/', substr($name, strlen($word))) === 1) {
                return substr($name, strlen($word));
            }

            if (! $prefix && str_ends_with($name, $word) && strlen($name) > strlen($word)) {
                return substr($name, 0, -strlen($word));
            }
        }

        return null;
    }

    /**
     * Tells whether a class name contains the model name as a StudlyCase word.
     *
     * The model name must start the class name or follow a lowercase letter or digit, and end it or be
     * followed by an uppercase letter: StorePostRequest contains Post, Poster does not.
     *
     * @param  string  $class  Short class name
     * @param  string  $model  Short model name
     *
     * @return bool True when the class name contains the model name as a word
     */
    private function containsWord(string $class, string $model): bool
    {
        return preg_match('/(?:^|(?<=[a-z0-9]))'.preg_quote($model, '/').'(?=[A-Z]|$)/', $class) === 1;
    }

    /**
     * Describes the move of a class into a module.
     *
     * @param  string  $source  File of the class
     * @param  array{fqcn: string, name: string, namespace: string, abstract: bool, parent: string|null}  $class  The class
     * @param  string  $type  Module subfolder
     * @param  string  $module  Module name
     * @param  string|null  $note  Why the class goes to this module, when its name alone does not say it
     *
     * @return Move The planned move
     */
    private function move(string $source, array $class, string $type, string $module, ?string $note = null): Move
    {
        $namespace = sprintf('%s\\%s\\%s', $this->modulesNamespace, $module, $type);

        return new Move(
            source: $source,
            destination: sprintf('%s/%s/%s/%s.php', $this->modulesPath, $module, $type, $class['name']),
            class: $class['name'],
            oldClass: $class['fqcn'],
            newClass: $namespace.'\\'.$class['name'],
            namespace: $namespace,
            type: $type,
            module: $module,
            note: $note,
        );
    }

    /**
     * Destinations that two classes share or that exist already.
     *
     * @param  list<Move>  $moves  Planned moves
     *
     * @return list<string> One message per conflict
     */
    private function conflicts(array $moves): array
    {
        $errors = [];
        $destinations = [];

        foreach ($moves as $move) {
            if (isset($destinations[$move->destination])) {
                $errors[] = sprintf('Several classes have the same destination: %s (%s and %s)', $move->destination, $destinations[$move->destination], $move->oldClass);
            } elseif ($this->files->exists($move->destination)) {
                $errors[] = sprintf('Destination already exists: %s (for %s)', $move->destination, $move->oldClass);
            }

            $destinations[$move->destination] ??= $move->oldClass;
        }

        return $errors;
    }
}
