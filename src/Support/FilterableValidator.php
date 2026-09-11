<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Exceptions\InvalidFilterableOperatorException;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

class FilterableValidator
{
    /**
     * @throws InvalidFilterableOperatorException
     * @throws InvalidRelationFilterException
     */
    public function validateAll(): void
    {
        $allowedOperators = config('filters.operators', []);

        foreach ($this->discoverModelClasses() as $modelClass) {
            $this->validateModel($modelClass, $allowedOperators);
        }
    }

    protected function validateModel(string $modelClass, array $allowedOperators): void
    {
        $reflection = new ReflectionClass($modelClass);
        $attribute = $reflection->getAttributes(Filterable::class)[0] ?? null;

        if ($attribute == null) {
            return;
        }

        /** @var Filterable $filterable */
        $filterable = $attribute->newInstance();

        foreach ($filterable->columns as $key => $value) {
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
     * @throws InvalidRelationFilterException
     */
    protected function validateRelationFilter(string $modelClass, string $column, RelationFilter $definition): void
    {
        if (! method_exists($modelClass, $definition->relation)) {
            throw InvalidRelationFilterException::relationNotFound($modelClass, $column, $definition->relation);
        }

        try {
            /** @var Model $instance */
            $instance = new $modelClass;
            $result = $instance->{$definition->relation}();
        } catch (Throwable) {
            throw InvalidRelationFilterException::notARelation($modelClass, $column, $definition->relation);
        }

        if (! $result instanceof Relation) {
            throw InvalidRelationFilterException::notARelation($modelClass, $column, $definition->relation);
        }
    }

    /**
     * @return array<int, class-string<Model>>
     */
    protected function discoverModelClasses(): array
    {
        $paths = $this->resolveModelPaths();

        if (empty($paths)) {
            return [];
        }

        $classes = [];

        foreach ((new Finder)->files()->in($paths)->name('*.php') as $file) {
            $class = $this->classFromFile($file->getRealPath());

            if ($class !== null && class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    protected function resolveModelPaths(): array
    {
        $configuredPaths = config('filters.model_paths', []);

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

    protected function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        if (! preg_match('/namespace\s+(.+?);/', $contents, $namespaceMatch)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return $namespaceMatch[1].'\\'.$classMatch[1];
    }
}
