<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Exceptions\InvalidAccessException;
use Adonyarik\ConsistentApi\Exceptions\InvalidFilterableOperatorException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationSortException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Checks the #[Filterable] / #[Sortable] declarations of the application's models.
 *
 * ConsistentApiProvider runs it while booting in local and testing environments when
 * consistentapi.filters.validate_on_boot is on. It scans consistentapi.filters.model_paths for Eloquent models and fails fast with a
 * descriptive exception on an access group missing from consistentapi.accesses, a column declared twice,
 * an operator not enabled in consistentapi.filters.operators, a RelationFilter with an operator other than
 * eq / like, or a RelationFilter / RelationSort that does not point to a usable relation. These mistakes would otherwise
 * show up at request time, as errors or as columns silently ignored.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class FilterableSortableValidator
{
    /**
     * Validates every model found in consistentapi.filters.model_paths.
     *
     * Stops at the first invalid declaration. Each call scans the files again; it runs once per boot.
     *
     * @throws InvalidFilterableOperatorException When a #[Filterable] operator is not enabled in config
     * @throws InvalidRelationFilterException When a RelationFilter has an unknown operator or points to a missing relation
     * @throws InvalidRelationSortException When a RelationSort points to a missing or unsupported relation
     * @throws InvalidAccessException When an access group is unknown or a column is declared twice
     *
     * @see FilterableSortableValidator::discoverModelClasses()
     * @see FilterableSortableValidator::validateModel()
     */
    public function validateAll(): void
    {
        $allowedOperators = config('consistentapi.filters.operators', []);

        foreach ($this->discoverModelClasses() as $modelClass) {
            $this->validateModel($modelClass, $allowedOperators);
        }
    }

    /**
     * Validates the declarations of one model.
     *
     * Checks access groups and column uniqueness of both attributes first, then the operators and relation
     * filters of every #[Filterable], then the relation sorts of every #[Sortable].
     *
     * @param  class-string<Model>  $modelClass  Model to validate
     * @param  array<int, string>  $allowedOperators  Operators enabled in config('consistentapi.filters.operators')
     *
     * @throws InvalidFilterableOperatorException When a #[Filterable] operator is not enabled in config
     * @throws InvalidRelationFilterException When a RelationFilter has an unknown operator or points to a missing relation
     * @throws InvalidRelationSortException When a RelationSort points to a missing or unsupported relation
     * @throws InvalidAccessException When an access group is unknown or a column is declared twice
     *
     * @see FilterableSortableValidator::validateAccesses()
     * @see FilterableSortableValidator::validateFilterableColumns()
     * @see FilterableSortableValidator::validateRelationSort()
     */
    protected function validateModel(string $modelClass, array $allowedOperators): void
    {
        $reflection = new ReflectionClass($modelClass);

        $this->validateAccesses($modelClass, $reflection, Filterable::class);
        $this->validateAccesses($modelClass, $reflection, Sortable::class);

        foreach ($reflection->getAttributes(Filterable::class) as $attribute) {
            /** @var Filterable $filterable */
            $filterable = $attribute->newInstance();

            $this->validateFilterableColumns($modelClass, $filterable->columns, $allowedOperators);
        }

        foreach ($reflection->getAttributes(Sortable::class) as $attribute) {
            /** @var Sortable $sortable */
            $sortable = $attribute->newInstance();

            foreach ($sortable->columns as $key => $value) {
                if ($value instanceof RelationSort) {
                    $this->validateRelationSort($modelClass, (string) $key, $value);
                }
            }
        }
    }

    /**
     * Checks the access groups and column uniqueness of one attribute kind.
     *
     * Access names must exist in consistentapi.accesses; a column may appear in only one instance of the
     * attribute, whatever the accesses of those instances.
     *
     * @param  class-string<Model>  $modelClass  Model to validate
     * @param  ReflectionClass<Model>  $reflection  Reflection of the model
     * @param  class-string<Filterable|Sortable>  $attributeClass  Attribute kind to check
     *
     * @throws InvalidAccessException When an access group is unknown or a column is declared twice
     */
    protected function validateAccesses(string $modelClass, ReflectionClass $reflection, string $attributeClass): void
    {
        $definedAccesses = array_map('strval', array_keys((array) config('consistentapi.accesses', [])));
        $attributeName = class_basename($attributeClass);
        $seenColumns = [];

        foreach ($reflection->getAttributes($attributeClass) as $attribute) {
            /** @var Filterable|Sortable $instance */
            $instance = $attribute->newInstance();

            foreach ($instance->accesses as $access) {
                if (! in_array($access, $definedAccesses, true)) {
                    throw InvalidAccessException::unknownAccess($modelClass, $attributeName, (string) $access);
                }
            }

            foreach ($instance->columns as $key => $value) {
                $column = (string) (is_int($key) ? $value : $key);

                if (isset($seenColumns[$column])) {
                    throw InvalidAccessException::duplicateColumn($modelClass, $attributeName, $column);
                }

                $seenColumns[$column] = true;
            }
        }
    }

    /**
     * Checks the operators and relation filters of one #[Filterable].
     *
     * Plain columns need no check. A RelationFilter must point to a relation; operators, listed by name or
     * with validation rules, must be enabled in config('consistentapi.filters.operators').
     *
     * @param  class-string<Model>  $modelClass  Model to validate
     * @param  array<int|string, mixed>  $columns  Column list of the attribute
     * @param  array<int, string>  $allowedOperators  Operators enabled in config('consistentapi.filters.operators')
     *
     * @throws InvalidFilterableOperatorException When an operator is not enabled in config
     * @throws InvalidRelationFilterException When a RelationFilter has an unknown operator or points to a missing relation
     *
     * @see FilterableSortableValidator::validateRelationFilter()
     */
    protected function validateFilterableColumns(string $modelClass, array $columns, array $allowedOperators): void
    {
        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            if ($value instanceof RelationFilter) {
                $this->validateRelationFilter($modelClass, (string) $key, $value);

                continue;
            }

            $declaredOperators = [];

            foreach ((array) $value as $operatorKey => $operatorValue) {
                $declaredOperators[] = is_int($operatorKey) ? $operatorValue : $operatorKey;
            }

            $invalid = array_diff($declaredOperators, $allowedOperators);

            if (! empty($invalid)) {
                throw InvalidFilterableOperatorException::forColumn($modelClass, (string) $key, $invalid);
            }
        }
    }

    /**
     * Checks that a RelationFilter has a supported operator and points to a relation method.
     *
     * The operator must be eq or like, exactly as written, so a typo such as 'equal' is not taken for a
     * substring match. Any relation type will do, since CanFilter filters through whereHas(). A dotted
     * path (author.profile) is followed step by step, each step on the model the previous relation
     * returns, as whereHas() follows it.
     *
     * @param  class-string<Model>  $modelClass  Model to validate
     * @param  string  $column  Filter column holding the definition
     * @param  RelationFilter  $definition  The relation filter
     *
     * @throws InvalidRelationFilterException When the operator is unknown, or a method of the path is missing or is not a relation
     *
     * @see RelationFilter::OPERATORS
     * @see FilterableSortableValidator::callRelation()
     */
    protected function validateRelationFilter(string $modelClass, string $column, RelationFilter $definition): void
    {
        if (! in_array($definition->operator, RelationFilter::OPERATORS, true)) {
            throw InvalidRelationFilterException::unknownOperator($modelClass, $column, $definition->operator);
        }

        $stepClass = $modelClass;

        foreach (explode('.', $definition->relation) as $step) {
            if (! method_exists($stepClass, $step)) {
                throw InvalidRelationFilterException::relationNotFound($modelClass, $column, $definition->relation);
            }

            $relation = $this->callRelation($stepClass, $step);

            if ($relation === null) {
                throw InvalidRelationFilterException::notARelation($modelClass, $column, $definition->relation);
            }

            $stepClass = $relation->getRelated()::class;
        }
    }

    /**
     * Checks that a RelationSort points to a relation CanSort can order by.
     *
     * The relation must exist and must not be a MorphTo, the one type CanSort::applyRelationSort() cannot
     * order by and would silently ignore: its related table depends on each row.
     *
     * @param  class-string<Model>  $modelClass  Model to validate
     * @param  string  $column  Sort column holding the definition
     * @param  RelationSort  $definition  The relation sort
     *
     * @throws InvalidRelationSortException When the method is missing, is not a relation or has an unsupported type
     *
     * @see FilterableSortableValidator::callRelation()
     */
    protected function validateRelationSort(string $modelClass, string $column, RelationSort $definition): void
    {
        if (! method_exists($modelClass, $definition->relation)) {
            throw InvalidRelationSortException::relationNotFound($modelClass, $column, $definition->relation);
        }

        $relation = $this->callRelation($modelClass, $definition->relation);

        if ($relation === null) {
            throw InvalidRelationSortException::notARelation($modelClass, $column, $definition->relation);
        }

        if ($relation instanceof MorphTo) {
            throw InvalidRelationSortException::unsupportedRelation($modelClass, $column, $definition->relation, $relation::class);
        }
    }

    /**
     * Calls the relation method on a fresh model; null when it fails or returns something else.
     *
     * The model is created without attributes and nothing is queried. Any exception, for example from a
     * method that needs arguments, counts as not a relation.
     *
     * @param  class-string<Model>  $modelClass  Model to instantiate
     * @param  string  $method  Relation method to call
     *
     * @return Relation|null The relation, or null when the method does not return one
     */
    protected function callRelation(string $modelClass, string $method): ?Relation
    {
        try {
            /** @var Model $instance */
            $instance = new $modelClass;
            $result = $instance->{$method}();
        } catch (Throwable) {
            return null;
        }

        return $result instanceof Relation ? $result : null;
    }

    /**
     * Finds the Eloquent model classes in consistentapi.filters.model_paths.
     *
     * Reads every *.php file under the resolved directories, recursively, and keeps the declared classes
     * that autoload and extend Model; a file declaring several classes contributes each of them. Abstract
     * classes are skipped: they cannot be instantiated to check their relations, and their #[Filterable] /
     * #[Sortable] attributes do not apply to subclasses, since CanFilter and CanSort read the attributes of
     * the model class itself.
     *
     * @return array<int, class-string<Model>> Model classes found
     *
     * @see FilterableSortableValidator::resolveModelPaths()
     * @see FilterableSortableValidator::classesFromFile()
     */
    protected function discoverModelClasses(): array
    {
        $paths = $this->resolveModelPaths();

        if (empty($paths)) {
            return [];
        }

        $classes = [];

        foreach ((new Finder)->files()->in($paths)->name('*.php') as $file) {
            foreach ($this->classesFromFile($file->getRealPath()) as $class) {
                if (class_exists($class) && is_subclass_of($class, Model::class)
                    && ! (new ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Turns consistentapi.filters.model_paths into existing directories.
     *
     * A path with * is expanded with glob() (app/Modules/{asterisk}/Models), other paths are kept when they
     * are directories; missing paths are skipped and duplicates removed.
     *
     * @return array<int, string> Directories to scan
     */
    protected function resolveModelPaths(): array
    {
        $configuredPaths = config('consistentapi.filters.model_paths', []);

        $resolved = [];

        foreach ($configuredPaths as $path) {
            if (str_contains($path, '*')) {
                $resolved = array_merge($resolved, glob($path, GLOB_ONLYDIR) ?: []);
            } elseif (is_dir($path)) {
                $resolved[] = $path;
            }
        }

        return array_unique($resolved);
    }

    /**
     * Reads the fully qualified names of the classes declared in a PHP file.
     *
     * Parses the tokens with PhpClassReader, so a "class" or "namespace" word in a comment or docblock
     * above the declaration does not hide a model from the validation, and neither does an interface or
     * another class declared before it in the same file.
     *
     * @param  string  $path  PHP file to read
     *
     * @return list<string> The class names, empty when the file cannot be read or declares no class
     *
     * @see PhpClassReader::classNames()
     */
    protected function classesFromFile(string $path): array
    {
        $contents = file_get_contents($path);

        return $contents === false ? [] : (new PhpClassReader)->classNames($contents);
    }
}
