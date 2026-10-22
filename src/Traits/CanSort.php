<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;

/**
 * Sorting of a model query by request input.
 *
 * Applies sort[column]=asc|desc input to the columns the model declares in #[Sortable] attributes; a
 * model without them has nothing to sort by. A column can also sort through a relation with RelationSort.
 * A declaration may be restricted to access groups of consistentapi.accesses; its columns then exist only
 * for callers with one of those groups. Every public method takes an optional list of accesses: null means
 * the accesses of the current request's route, none outside HTTP. The trait works on any Eloquent model;
 * ConsistentModel uses it.
 *
 * @method static \Illuminate\Database\Eloquent\Builder sort(array $columns, ?array $accesses = null)
 *
 * @package Adonyarik\ConsistentApi\Traits
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
trait CanSort
{
    /**
     * #[Sortable] declarations per model class, read once per process.
     *
     * @var array<class-string, list<array{columns: array<int|string, mixed>, accesses: list<string>}>>
     */
    private static array $sortDeclarations = [];

    /**
     * Applies sort input to the query (the sort() scope).
     *
     * Selects {table}.* when nothing is selected yet, so that columns of tables joined by scopes do not
     * override the model's attributes; a select made before is kept. Then adds an ORDER BY per input
     * column, in input order: {table}.{column} for a plain column, a relation subquery for a RelationSort
     * column (applyRelationSort()). A direction other than asc or desc
     * (case-insensitive) is skipped, as is an empty one (null or '' means the column was not set) and a
     * column not available to the accesses; rejecting such input is up to the caller, as
     * ConsistentController does with a 422 response. Only declared column names reach the query.
     *
     * @param  Builder  $query  Query of the model
     * @param  array<string, string|null>  $columns  Sort input: column => asc or desc
     * @param  list<string>|null  $accesses  Access groups to sort with; null for the current route's accesses
     *
     * @return Builder The sorted query
     *
     * @see CanSort::getNormalizedAllowedSorts()
     * @see CanSort::applyRelationSort()
     */
    public function scopeSort(Builder $query, array $columns, ?array $accesses = null): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->getTable().'.*');
        }

        $map = $this->getNormalizedAllowedSorts($accesses);

        foreach ($columns as $column => $direction) {
            $direction = is_string($direction) ? strtolower($direction) : null;

            if (! in_array($direction, ['asc', 'desc'], true) || ! array_key_exists($column, $map)) {
                continue;
            }

            $definition = $map[$column];

            $query = $definition instanceof RelationSort
                ? $this->applyRelationSort($query, $definition, $direction)
                : $query->orderBy($this->getTable().'.'.$column, $direction);
        }

        return $query;
    }

    /**
     * Orders by a column of a related model.
     *
     * Orders the main query by a correlated subquery that picks one related value per row: the smallest
     * non-null value for asc, the largest for desc, so a to-many relation (or a has-one with several rows)
     * never multiplies the rows and desc is not simply asc reversed. Rows without a related value sort as
     * NULL. The subquery is built like withMin() / withMax() build theirs, from the relation's existence
     * query: the relation's own conditions (->where(...), ofMany(), pivot conditions), the related
     * model's global scopes (soft deletes), the morph type of morph relations, custom keys and the alias
     * of a relation to the model's own table all apply, and names are quoted and prefixed by the grammar.
     * It works for every relation type except MorphTo, whose related table is not known in advance, on
     * every database and for every column type. MorphTo and methods that do not return a relation leave
     * the query unchanged; FilterableSortableValidator rejects them at boot where it runs.
     *
     * @param  Builder  $query  Query of the model
     * @param  RelationSort  $definition  Relation and column declared in #[Sortable]
     * @param  'asc'|'desc'  $direction  Sort direction
     *
     * @return Builder The sorted query
     *
     * @see Relation::getRelationExistenceQuery()
     */
    protected function applyRelationSort(Builder $query, RelationSort $definition, string $direction): Builder
    {
        $relation = Relation::noConstraints(fn () => $this->{$definition->relation}());

        if (! $relation instanceof Relation || $relation instanceof MorphTo) {
            return $query;
        }

        $related = $relation->getRelated();

        // A relation to the model's own table is queried under an alias, as withAggregate() does
        $column = $query->getQuery()->from === $relation->getQuery()->getQuery()->from
            ? $relation->getRelationCountHash(false).'.'.$definition->column
            : $related->qualifyColumn($definition->column);

        $subquery = $relation->getRelationExistenceQuery($related->newQuery(), $query, $column)
            ->mergeConstraintsFrom($relation->getQuery())
            ->toBase();

        // Orders from the relation definition would break the subquery
        $subquery->orders = null;
        $subquery->setBindings([], 'order');

        $subquery->whereNotNull($column)->orderBy($column, $direction)->limit(1);

        return $query->orderBy($subquery, $direction);
    }

    /**
     * Tells whether any column can be sorted by with the given accesses.
     *
     * ConsistentController rejects any sort input with "Sorting on this object is not allowed."
     * when this is false.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return bool True when at least one column is available
     *
     * @see CanSort::getAllowedSorts()
     */
    public function isSortable(?array $accesses = null): bool
    {
        return ! empty($this->getAllowedSorts($accesses));
    }

    /**
     * Columns that can be sorted by with the given accesses.
     *
     * Includes unrestricted columns and the columns of declarations restricted to one of the accesses.
     * ConsistentController answers 422 for an input column missing from this list.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return list<string> Column names as they appear in sort input
     *
     * @see CanSort::getNormalizedAllowedSorts()
     */
    public function getAllowedSorts(?array $accesses = null): array
    {
        return array_keys($this->getNormalizedAllowedSorts($accesses));
    }

    /**
     * Sorts available to the given accesses: column => null | RelationSort.
     *
     * Goes through getSortDeclarations() in declaration order and skips declarations restricted to
     * accesses the caller does not have. With null accesses the current route's accesses are resolved
     * through AccessResolver, only when a restricted declaration exists. A column declared twice keeps
     * its first definition.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return array<string, RelationSort|null> Column => RelationSort, or null for a column of the model's table
     *
     * @see CanSort::getSortDeclarations()
     * @see AccessResolver::current()
     */
    protected function getNormalizedAllowedSorts(?array $accesses = null): array
    {
        $normalized = [];

        foreach ($this->getSortDeclarations() as $declaration) {
            if ($declaration['accesses'] !== []) {
                $accesses ??= app(AccessResolver::class)->current();

                if (array_intersect($declaration['accesses'], $accesses) === []) {
                    continue;
                }
            }

            foreach ($declaration['columns'] as $key => $value) {
                $column = is_int($key) ? $value : $key;

                // A repeated column keeps its first declaration (FilterableSortableValidator forbids repeats)
                if (! array_key_exists($column, $normalized)) {
                    $normalized[$column] = is_int($key) ? null : $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Every #[Sortable] on the model.
     *
     * Read from the model class itself, so a model does not inherit the attributes of its parent class.
     * The declarations are cached per model class for the lifetime of the process. A model without
     * #[Sortable] has no declarations, so every sort input is rejected as unknown.
     *
     * @return list<array{columns: array<int|string, mixed>, accesses: list<string>}> Declarations in source order, empty without the attribute
     *
     * @see Sortable
     */
    protected function getSortDeclarations(): array
    {
        return self::$sortDeclarations[static::class] ??= array_map(
            function ($attribute): array {
                /** @var Sortable $sortable */
                $sortable = $attribute->newInstance();

                return ['columns' => $sortable->columns, 'accesses' => $sortable->accesses];
            },
            (new ReflectionClass($this))->getAttributes(Sortable::class),
        );
    }
}
