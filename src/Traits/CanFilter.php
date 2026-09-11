<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * @method static \Illuminate\Database\Eloquent\Builder filter(array $filters)
 */
trait CanFilter
{
    protected array $filter = [];

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $query->select($this->getTable().'.*');

        return $this->handleFilter($query, $filters);
    }

    protected function handleFilter(Builder $query, array $filters): Builder
    {
        $map = $this->getNormalizedAllowedFilters();

        foreach ($filters as $column => $filter) {
            if (! array_key_exists($column, $map)) {
                continue;
            }

            $definition = $map[$column];

            if ($definition instanceof RelationFilter) {
                $query = $this->applyRelationFilter($query, $definition, $filter);

                continue;
            }

            $qualifiedColumn = $this->getTable().'.'.$column;

            $query = is_array($filter)
                ? $this->applyArrayFilter($query, $qualifiedColumn, $column, $filter)
                : $this->applyScalarFilter($query, $qualifiedColumn, $filter);
        }

        return $query;
    }

    protected function applyRelationFilter(Builder $query, RelationFilter $definition, mixed $value): Builder
    {
        if (is_array($value)) {
            return $query;
        }

        return $query->whereHas($definition->relation, function (Builder $relationQuery) use ($definition, $value) {
            $table = $relationQuery->getModel()->getTable();

            if ($definition->operator === 'eq') {
                $relationQuery->where("$table.$definition->column", '=', $value);

                return;
            }

            $relationQuery->where(
                "$table.$definition->column",
                DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE',
                "%$value%"
            );
        });
    }

    protected function applyScalarFilter(Builder $query, string $column, mixed $value): Builder
    {
        return $query->where(
            $column,
            DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE',
            "%$value%"
        );
    }

    protected function applyArrayFilter(Builder $query, string $qualifiedColumn, string $column, array $filter): Builder
    {
        $allowedKeys = $this->getAllowedFilterKeys($column);

        if ($allowedKeys !== null && array_is_list($filter) && $this->isScalarList($filter)) {
            return $query->whereIn($qualifiedColumn, $filter);
        }

        foreach ($filter as $operator => $value) {
            if ($allowedKeys !== null && ! in_array($operator, $allowedKeys, true)) {
                continue;
            }

            $query = match ($operator) {
                'eq' => $query->where($qualifiedColumn, '=', $value),
                'not_eq' => $query->where($qualifiedColumn, '!=', $value),
                'like' => $query->where(
                    $qualifiedColumn,
                    DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE',
                    "%$value%"
                ),
                'from' => $query->where($qualifiedColumn, '>=', Carbon::parse($value)->startOfDay()),
                'to' => $query->where($qualifiedColumn, '<=', Carbon::parse($value)->endOfDay()),
                'min' => $query->where($qualifiedColumn, '>=', $value),
                'max' => $query->where($qualifiedColumn, '<=', $value),
                'in' => $query->whereIn($qualifiedColumn, $this->normalizeToArray($value)),
                'not_in' => $query->whereNotIn($qualifiedColumn, $this->normalizeToArray($value)),
                'null' => $this->toBool($value)
                    ? $query->whereNull($qualifiedColumn)
                    : $query->whereNotNull($qualifiedColumn),
                default => $query,
            };
        }

        return $query;
    }

    protected function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return array_map('trim', explode(',', (string) $value));
    }

    protected function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function isFilterable(): bool
    {
        return ! empty($this->getAllowedFilters());
    }

    public function getAllowedFilters(): array
    {
        return array_keys($this->getNormalizedAllowedFilters());
    }

    /**
     * @return array<int, string>|null
     */
    public function getAllowedFilterKeys(string $column): ?array
    {
        $operators = $this->getNormalizedAllowedFilters()[$column] ?? null;

        if ($operators === null || $operators instanceof RelationFilter) {
            return null;
        }

        return array_keys($operators);
    }

    public function filterRulesMap(): array
    {
        return $this->getNormalizedAllowedFilters();
    }

    protected function getNormalizedAllowedFilters(): array
    {
        $normalized = [];

        foreach ($this->getRawAllowedFilters() as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = null;

                continue;
            }

            if ($value instanceof RelationFilter) {
                $normalized[$key] = $value;

                continue;
            }

            $operators = [];

            foreach ((array) $value as $operatorKey => $operatorValue) {
                $operators = is_int($operatorKey)
                    ? $operators + [$operatorValue => []]
                    : $operators + [$operatorKey => (array) $operatorValue];
            }

            $normalized[$key] = $operators;
        }

        return $normalized;
    }

    protected function getRawAllowedFilters(): array
    {
        $attribute = (new ReflectionClass($this))->getAttributes(Filterable::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->columns;
        }

        return $this->filter;
    }
}
