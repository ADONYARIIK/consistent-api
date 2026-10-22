<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Exceptions\InvalidRelationFilterException;
use Adonyarik\ConsistentApi\Support\AccessResolver;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Carbon;
use ReflectionClass;

/**
 * Filtering of a model query by request input.
 *
 * Applies filter[column]=value and filter[column][operator]=value input to the columns the model declares
 * in #[Filterable] attributes; a model without them has nothing to filter by. A declaration may be
 * restricted to access groups of consistentapi.accesses; its columns then exist only for callers with one
 * of those groups. Every public method that depends on the declarations takes an optional list of
 * accesses: null means the accesses of the current request's route, none outside HTTP. ConsistentController
 * checks the input against getAllowedFilters() / getAllowedFilterKeys() / filterValueError() before it
 * calls the filter() scope. Input values are bound as query parameters and LIKE patterns are escaped.
 *
 * @method static \Illuminate\Database\Eloquent\Builder filter(array $filters, ?array $accesses = null)
 *
 * @package Adonyarik\ConsistentApi\Traits
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
trait CanFilter
{
    /**
     * #[Filterable] declarations per model class, read once per process.
     *
     * @var array<class-string, list<array{columns: array<int|string, mixed>, accesses: list<string>}>>
     */
    private static array $filterDeclarations = [];

    /**
     * Applies filter input to the query (the filter() scope).
     *
     * Selects {table}.* when nothing is selected yet, so that columns of tables joined by other scopes or
     * relation sorts do not override the model's attributes; a select made before, such as withCount() or
     * a scope's addSelect(), is kept. Then applies every filter through handleFilter(). Columns that are
     * not declared or not available to the accesses are skipped silently: rejecting them is up to the
     * caller, as ConsistentController does with a 422 response.
     *
     * @param  Builder  $query  Query of the model
     * @param  array<string, mixed>  $filters  Filter input: column => scalar, list of values or operator => value
     * @param  list<string>|null  $accesses  Access groups to filter with; null for the current route's accesses
     *
     * @return Builder The filtered query
     *
     * @throws InvalidRelationFilterException When a RelationFilter of the input declares an unknown operator
     *
     * @see CanFilter::handleFilter()
     */
    public function scopeFilter(Builder $query, array $filters, ?array $accesses = null): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->getTable().'.*');
        }

        return $this->handleFilter($query, $filters, $accesses);
    }

    /**
     * Applies each filter by the column's definition.
     *
     * Looks every input column up in getNormalizedAllowedFilters(): a RelationFilter column goes to
     * applyRelationFilter(), an array value to applyArrayFilter() with the column's declared operators and
     * a scalar value to applyScalarFilter(). An empty value (null or '') means the filter was not set and is
     * skipped, as is a column missing from the definitions. Only declared column names reach the query, so
     * the input keys never become SQL.
     *
     * @param  Builder  $query  Query of the model
     * @param  array<string, mixed>  $filters  Filter input: column => scalar, list of values or operator => value
     * @param  list<string>|null  $accesses  Access groups to filter with; null for the current route's accesses
     *
     * @return Builder The filtered query
     *
     * @throws InvalidRelationFilterException When a RelationFilter of the input declares an unknown operator
     *
     * @see CanFilter::getNormalizedAllowedFilters()
     */
    protected function handleFilter(Builder $query, array $filters, ?array $accesses = null): Builder
    {
        $map = $this->getNormalizedAllowedFilters($accesses);

        foreach ($filters as $column => $filter) {
            if ($this->isEmptyFilterValue($filter) || ! array_key_exists($column, $map)) {
                continue;
            }

            $definition = $map[$column];

            if ($definition instanceof RelationFilter) {
                $query = $this->applyRelationFilter($query, $column, $definition, $filter);

                continue;
            }

            $qualifiedColumn = $this->getTable().'.'.$column;

            $query = is_array($filter)
                ? $this->applyArrayFilter($query, $qualifiedColumn, $filter, $definition === null ? null : array_keys($definition))
                : $this->applyScalarFilter($query, $qualifiedColumn, $filter);
        }

        return $query;
    }

    /**
     * Filters by a column of a related model through whereHas().
     *
     * Compares {related_table}.{column} of the relation named in the definition: by equality with the
     * 'eq' operator, by an escaped substring match with 'like'. Any other operator is a declaration error
     * and throws instead of falling back to a substring match. A relation filter takes one value, so an
     * array value leaves the query unchanged.
     *
     * @param  Builder  $query  Query of the model
     * @param  string  $column  Filter column holding the definition
     * @param  RelationFilter  $definition  Relation, column and operator declared in #[Filterable]
     * @param  mixed  $value  Filter value from the input
     *
     * @return Builder The filtered query
     *
     * @throws InvalidRelationFilterException When the definition's operator is neither eq nor like
     *
     * @see RelationFilter
     * @see CanFilter::whereContains()
     */
    protected function applyRelationFilter(Builder $query, string $column, RelationFilter $definition, mixed $value): Builder
    {
        if (! in_array($definition->operator, RelationFilter::OPERATORS, true)) {
            throw InvalidRelationFilterException::unknownOperator(static::class, $column, $definition->operator);
        }

        if (is_array($value)) {
            return $query;
        }

        return $query->whereHas($definition->relation, function (Builder $relationQuery) use ($definition, $value) {
            $relatedColumn = $relationQuery->getModel()->getTable().'.'.$definition->column;

            if ($definition->operator === 'eq') {
                $relationQuery->where($relatedColumn, '=', $value);

                return;
            }

            $this->whereContains($relationQuery, $relatedColumn, $value);
        });
    }

    /**
     * Applies a scalar filter value as a substring search.
     *
     * filter[column]=value matches rows whose column contains the value literally, see whereContains().
     * Called directly, it applies to every filterable column; through ConsistentController a column declared
     * with operators takes operators only.
     *
     * @param  Builder  $query  Query of the model
     * @param  string  $column  Column qualified with the model's table
     * @param  mixed  $value  Filter value from the input
     *
     * @return Builder The filtered query
     *
     * @see CanFilter::whereContains()
     */
    protected function applyScalarFilter(Builder $query, string $column, mixed $value): Builder
    {
        return $this->whereContains($query, $column, $value);
    }

    /**
     * Adds a "column contains value" condition with the value taken literally.
     *
     * ILIKE (case-insensitive) when the query runs on a PostgreSQL connection, LIKE on any other, where
     * case sensitivity follows the collation: MySQL with a _bin or _cs collation and SQLite for non-ASCII
     * letters compare case-sensitively. The grammar is the query's, not the default connection's. The
     * value is bound as a parameter, and its LIKE wildcards are escaped with an explicit ESCAPE '!' clause,
     * so % and _ in the input match themselves instead of any text: filter[name]=% finds names that
     * contain a percent sign. '!' is written the same way in an SQL literal on every database, unlike a
     * backslash.
     *
     * @param  Builder  $query  Query the condition is added to
     * @param  string  $column  Qualified column, from the model's declarations
     * @param  mixed  $value  Filter value from the input
     *
     * @return Builder The query with the condition
     *
     * @see CanFilter::escapeLike()
     */
    protected function whereContains(Builder $query, string $column, mixed $value): Builder
    {
        $pgsql = $query->getQuery()->getGrammar() instanceof PostgresGrammar;

        $sql = sprintf(
            "%s%s %s ? ESCAPE '!'",
            $query->getQuery()->getGrammar()->wrap($column),
            // PostgreSQL has no LIKE for numbers and dates, Laravel's own like conditions cast the same way
            $pgsql ? '::text' : '',
            $pgsql ? 'ILIKE' : 'LIKE',
        );

        return $query->whereRaw($sql, ['%'.$this->escapeLike((string) $value).'%']);
    }

    /**
     * Escapes the LIKE wildcards of a value for the ESCAPE '!' clause.
     *
     * Prefixes the escape character itself, % and _ (wildcards everywhere) and [ (a character class on SQL
     * Server) with '!', so the pattern matches the value literally.
     *
     * @param  string  $value  Value from the input
     *
     * @return string The value with every wildcard escaped
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $value);
    }

    /**
     * Applies operator filters (filter[column][operator]=value) to a column.
     *
     * Only the operators declared for the column are applied; a plain list of scalars is an `in` filter.
     * `in` / `not_in` take a list or a comma-separated string, `from` / `to` take a date and cover whole
     * days (column >= the day, column < the next day, both bound as YYYY-MM-DD strings, which every
     * database compares correctly with date and datetime columns), `null` takes a boolean (whereNull /
     * whereNotNull), `like` is an escaped substring match, and every other operator takes a single value. A value filterValueError() rejects is malformed
     * input and is skipped, as are empty values and unknown operators, so no input is reinterpreted.
     * config('consistentapi.filters.operators') is not consulted here: BaseSearchRequest and
     * ConsistentController check it. For a column
     * declared without operators every operator below applies; ConsistentController never lets such input
     * through, but a direct filter() call can.
     *
     * @param  Builder  $query  Query of the model
     * @param  string  $qualifiedColumn  Column qualified with the model's table
     * @param  array<int|string, mixed>  $filter  Operator => value, or a list of values
     * @param  list<string>|null  $allowedOperators  Operators declared for the column; null when it declares none
     *
     * @return Builder The filtered query
     *
     * @see CanFilter::filterValueError()
     * @see CanFilter::normalizeToArray()
     * @see CanFilter::whereContains()
     */
    protected function applyArrayFilter(Builder $query, string $qualifiedColumn, array $filter, ?array $allowedOperators): Builder
    {
        // A plain list of values is an `in` filter
        if ($allowedOperators !== null && array_is_list($filter) && $this->isScalarList($filter)) {
            $filter = ['in' => $filter];
        }

        foreach ($filter as $operator => $value) {
            if ($allowedOperators !== null && ! in_array($operator, $allowedOperators, true)) {
                continue;
            }

            if ($this->isEmptyFilterValue($value) || $this->filterValueError((string) $operator, $value) !== null) {
                continue;
            }

            if (in_array($operator, ['in', 'not_in'], true)) {
                $value = $this->normalizeToArray($value);

                if ($value === []) {
                    continue;
                }
            }

            if (in_array($operator, ['from', 'to'], true)) {
                $date = $this->toDate($value);

                if ($date === null) {
                    continue;
                }

                // Day strings compare correctly with date, datetime and timestamp columns on every database,
                // SQLite's text dates included, and the next day's start catches fractional seconds
                $query = $operator === 'from'
                    ? $query->where($qualifiedColumn, '>=', $date->toDateString())
                    : $query->where($qualifiedColumn, '<', $date->addDay()->toDateString());

                continue;
            }

            $query = match ($operator) {
                'eq' => $query->where($qualifiedColumn, '=', $value),
                'not_eq' => $query->where($qualifiedColumn, '!=', $value),
                'like' => $this->whereContains($query, $qualifiedColumn, $value),
                'min' => $query->where($qualifiedColumn, '>=', $value),
                'max' => $query->where($qualifiedColumn, '<=', $value),
                'in' => $query->whereIn($qualifiedColumn, $value),
                'not_in' => $query->whereNotIn($qualifiedColumn, $value),
                'null' => $this->toBool($value)
                    ? $query->whereNull($qualifiedColumn)
                    : $query->whereNotNull($qualifiedColumn),
                default => $query,
            };
        }

        return $query;
    }

    /**
     * Why a value cannot be used with an operator, null when it can.
     *
     * `in` / `not_in` take a list of scalars or a comma-separated string, `from` / `to` a date
     * (YYYY-MM-DD, optionally with a time, see toDate()), `null` a boolean ('1', 'true', 'on', 'yes', '0',
     * 'false', 'off', 'no'), and every other operator a single scalar. ConsistentController answers 422 with "Filtering by {column}.{operator} {reason}.",
     * applyArrayFilter() skips the value. Empty values are not checked: they mean the operator was not set.
     *
     * @param  string  $operator  Operator from the input
     * @param  mixed  $value  Operator value from the input
     *
     * @return string|null The reason, such as "takes a date"; null for a usable value
     */
    public function filterValueError(string $operator, mixed $value): ?string
    {
        if (in_array($operator, ['in', 'not_in'], true)) {
            return is_array($value) && ! $this->isScalarList($value) ? 'takes a list of values' : null;
        }

        if (is_array($value)) {
            return 'takes a single value';
        }

        return match ($operator) {
            'from', 'to' => $this->toDate($value) === null ? 'takes a date' : null,
            'null' => $this->toBool($value) === null ? 'takes true or false' : null,
            default => null,
        };
    }

    /**
     * Tells whether a filter value means "not set".
     *
     * null, an empty string and an empty array; an empty query string value such as filter[name]= arrives
     * as null or '' depending on the application's middleware.
     *
     * @param  mixed  $value  Filter value from the input
     *
     * @return bool True when the filter or operator is to be skipped
     */
    public function isEmptyFilterValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Tells whether none of the values is an array.
     *
     * applyArrayFilter() turns a list into an `in` filter only when it holds scalars; a list of arrays is
     * malformed input and is left as is.
     *
     * @param  array<int|string, mixed>  $values  Filter values to check
     *
     * @return bool True when no value is an array
     */
    protected function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turns an `in` / `not_in` value into a list without empty items.
     *
     * Accepts an array or a comma-separated string (filter[status][in]=draft,archived); string parts are
     * trimmed. Null and empty items are dropped, so empty input gives an empty list, which
     * applyArrayFilter() skips.
     *
     * @param  mixed  $value  Operator value from the input
     *
     * @return list<mixed> The values to match
     */
    protected function normalizeToArray(mixed $value): array
    {
        $values = is_array($value) ? $value : array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($values, fn ($item) => $item !== null && $item !== ''));
    }

    /**
     * Reads a date from filter input.
     *
     * Accepts YYYY-MM-DD, optionally followed by a time (T or a space, HH:MM, optional seconds, fraction
     * and time zone), as the `from` / `to` operators need it; only the day is used. The date must exist:
     * 2024-02-30, 0000-00-00 and relative words such as "next monday" are not dates, unlike for
     * Carbon::parse(). Anything else, and any value that is not a string, gives null.
     *
     * @param  mixed  $value  Operator value from the input
     *
     * @return Carbon|null The start of the day, null when the value is not a date
     */
    protected function toDate(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $matched = preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D',
            trim($value),
            $parts,
        );

        if ($matched !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if ((int) ($parts[4] ?? 0) > 23 || (int) ($parts[5] ?? 0) > 59 || (int) ($parts[6] ?? 0) > 59) {
            return null;
        }

        return Carbon::createMidnightDate((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /**
     * Reads a boolean from filter input.
     *
     * Uses FILTER_VALIDATE_BOOLEAN with FILTER_NULL_ON_FAILURE: '1', 'true', 'on' and 'yes' are true, '0',
     * 'false', 'off' and 'no' are false, anything else is null rather than silently false. The `null`
     * operator takes its value through it.
     *
     * @param  mixed  $value  Operator value from the input
     *
     * @return bool|null The boolean the value stands for, null when it stands for none
     */
    protected function toBool(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Tells whether any column can be filtered with the given accesses.
     *
     * ConsistentController rejects any filter input with "Filtering on this object is not allowed."
     * when this is false.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return bool True when at least one column is available
     *
     * @see CanFilter::getAllowedFilters()
     */
    public function isFilterable(?array $accesses = null): bool
    {
        return ! empty($this->getAllowedFilters($accesses));
    }

    /**
     * Columns that can be filtered with the given accesses.
     *
     * Includes unrestricted columns and the columns of declarations restricted to one of the accesses.
     * ConsistentController answers 422 for an input column missing from this list.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return list<string> Column names as they appear in filter input
     *
     * @see CanFilter::getNormalizedAllowedFilters()
     */
    public function getAllowedFilters(?array $accesses = null): array
    {
        return array_keys($this->getNormalizedAllowedFilters($accesses));
    }

    /**
     * Operators declared for a filterable column.
     *
     * Returns the operator names of the column's #[Filterable] declaration. null means the
     * column takes only a scalar value: it is declared without operators, is a RelationFilter, or is not
     * available to the accesses at all. ConsistentController uses it to reject undeclared nested keys.
     *
     * @param  string  $column  Column name as it appears in filter input
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return array<int, string>|null Declared operator names, null when the column takes no operators
     *
     * @see CanFilter::getNormalizedAllowedFilters()
     */
    public function getAllowedFilterKeys(string $column, ?array $accesses = null): ?array
    {
        $operators = $this->getNormalizedAllowedFilters($accesses)[$column] ?? null;

        if ($operators === null || $operators instanceof RelationFilter) {
            return null;
        }

        return array_keys($operators);
    }

    /**
     * Filter definitions for building validation rules.
     *
     * Public access to getNormalizedAllowedFilters() for BaseSearchRequest::filterValueRules(), which turns
     * declared operator rules (['eq' => ['email']]) into rules for filter.{column}.{operator}.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return array<string, array<string, array<mixed>>|RelationFilter|null> Column => operator rules, RelationFilter or null
     *
     * @see CanFilter::getNormalizedAllowedFilters()
     */
    public function filterRulesMap(?array $accesses = null): array
    {
        return $this->getNormalizedAllowedFilters($accesses);
    }

    /**
     * Filters available to the given accesses: column => null | operators | RelationFilter.
     *
     * Goes through getFilterDeclarations() in declaration order and skips declarations restricted to
     * accesses the caller does not have. With null accesses the current route's accesses are resolved
     * through AccessResolver, only when a restricted declaration exists. A column declared twice keeps
     * its first definition.
     *
     * @param  list<string>|null  $accesses  Access groups to check; null for the current route's accesses
     *
     * @return array<string, array<string, array<mixed>>|RelationFilter|null> Column => operator rules, RelationFilter or null
     *
     * @see CanFilter::getFilterDeclarations()
     * @see CanFilter::normalizeFilterDefinition()
     * @see AccessResolver::current()
     */
    protected function getNormalizedAllowedFilters(?array $accesses = null): array
    {
        $normalized = [];

        foreach ($this->getFilterDeclarations() as $declaration) {
            if ($declaration['accesses'] !== []) {
                $accesses ??= app(AccessResolver::class)->current();

                if (array_intersect($declaration['accesses'], $accesses) === []) {
                    continue;
                }
            }

            foreach ($declaration['columns'] as $key => $value) {
                $column = is_int($key) ? $value : $key;

                // A repeated column keeps its first declaration (FilterableSortableValidator forbids repeats)
                if (array_key_exists($column, $normalized)) {
                    continue;
                }

                $normalized[$column] = $this->normalizeFilterDefinition($key, $value);
            }
        }

        return $normalized;
    }

    /**
     * Normalizes one entry of a #[Filterable] column list.
     *
     * A list item ('title') is a column without operators and becomes null; a RelationFilter is kept as is;
     * otherwise the value lists operators, by name (['eq', 'in']) or with validation rules
     * (['eq' => ['email']]), and becomes operator => rules.
     *
     * @param  int|string  $key  Entry key: an index for a plain column, the column name otherwise
     * @param  mixed  $value  Entry value: the column name, operators or a RelationFilter
     *
     * @return array<string, array<mixed>>|RelationFilter|null Operator => rules, the RelationFilter, or null for a plain column
     */
    protected function normalizeFilterDefinition(int|string $key, mixed $value): array|RelationFilter|null
    {
        if (is_int($key)) {
            return null;
        }

        if ($value instanceof RelationFilter) {
            return $value;
        }

        $operators = [];

        foreach ((array) $value as $operatorKey => $operatorValue) {
            $operators = is_int($operatorKey)
                ? $operators + [$operatorValue => []]
                : $operators + [$operatorKey => (array) $operatorValue];
        }

        return $operators;
    }

    /**
     * Every #[Filterable] on the model.
     *
     * Read from the model class itself, so a model does not inherit the attributes of its parent class.
     * The declarations are cached per model class for the lifetime of the process. A model without
     * #[Filterable] has no declarations, so every filter input is rejected as unknown.
     *
     * @return list<array{columns: array<int|string, mixed>, accesses: list<string>}> Declarations in source order, empty without the attribute
     *
     * @see Filterable
     */
    protected function getFilterDeclarations(): array
    {
        return self::$filterDeclarations[static::class] ??= array_map(
            function ($attribute): array {
                /** @var Filterable $filterable */
                $filterable = $attribute->newInstance();

                return ['columns' => $filterable->columns, 'accesses' => $filterable->accesses];
            },
            (new ReflectionClass($this))->getAttributes(Filterable::class),
        );
    }
}
