<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * @method static \Illuminate\Database\Eloquent\Builder sort(array $columns)
 */
trait CanSort
{
    protected array $sort = [];

    public function scopeSort(Builder $query, array $columns): Builder
    {
        $map = $this->getNormalizedAllowedSorts();

        foreach ($columns as $column => $direction) {
            if (! array_key_exists($column, $map)) {
                continue;
            }

            $definition = $map[$column];

            $query = $definition instanceof RelationSort
                ? $this->applyRelationSort($query, $definition, $direction)
                : $query->orderBy($this->getTable().'.'.$column, $direction);
        }

        return $query;
    }

    protected function applyRelationSort(Builder $query, RelationSort $definition, string $direction): Builder
    {
        $relation = $this->{$definition->relation}();

        return match (true) {
            $relation instanceof BelongsTo, $relation instanceof HasOne => $this->applySingleRelationSort($query, $relation, $definition->column, $direction),
            $relation instanceof BelongsToMany, $relation instanceof HasMany => $this->applyMultiRelationSort($query, $relation, $definition->column, $direction),
            default => $query,
        };
    }

    protected function applySingleRelationSort(Builder $query, BelongsTo|HasOne $relation, string $column, string $direction): Builder
    {
        $relatedTable = $relation->getRelated()->getTable();

        [$foreignKey, $ownerKey] = $relation instanceof BelongsTo
            ? [$relation->getQualifiedForeignKeyName(), $relation->getQualifiedOwnerKeyName()]
            : [$relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName()];

        $query = $this->leftJoinOnce($query, $relatedTable, $foreignKey, '=', $ownerKey);

        if (empty($query->getQuery()->columns)) {
            $query->select($this->getTable().'.*');
        }

        return $query->orderBy($relatedTable.'.'.$column, $direction);
    }

    protected function applyMultiRelationSort(Builder $query, BelongsToMany|HasMany $relation, string $column, string $direction): Builder
    {
        $relatedTable = $relation->getRelated()->getTable();
        $aggregateExpression = $this->aggregateExpression($relatedTable, $column);

        $subquery = $relation instanceof BelongsToMany
            ? $this->belongsToManySortSubquery($relation, $aggregateExpression)
            : $this->hasManySortSubquery($relation, $aggregateExpression);

        return $query->orderBy($subquery, $direction);
    }

    protected function belongsToManySortSubquery(BelongsToMany $relation, string $aggregateExpression)
    {
        $pivotTable = $relation->getTable();
        $relatedTable = $relation->getRelated()->getTable();
        $relatedKey = $relation->getRelated()->getKeyName();

        return DB::table($pivotTable)
            ->join($relatedTable, "$relatedTable.$relatedKey", '=', "$pivotTable.{$relation->getRelatedPivotKeyName()}")
            ->whereColumn("$pivotTable.{$relation->getForeignPivotKeyName()}", $this->getQualifiedKeyName())
            ->selectRaw($aggregateExpression);
    }

    protected function hasManySortSubquery(HasMany $relation, string $aggregateExpression)
    {
        $relatedTable = $relation->getRelated()->getTable();

        return DB::table($relatedTable)
            ->whereColumn("$relatedTable.{$relation->getForeignKeyName()}", $relation->getQualifiedParentKeyName())
            ->selectRaw($aggregateExpression);
    }

    protected function aggregateExpression(string $table, string $column): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "STRING_AGG($table.$column, ',' ORDER BY $table.$column ASC)"
            : "GROUP_CONCAT($table.$column ORDER BY $table.$column ASC SEPARATOR ',')";
    }

    public function isSortable(): bool
    {
        return ! empty($this->getAllowedSorts());
    }

    public function getAllowedSorts(): array
    {
        return array_keys($this->getNormalizedAllowedSorts());
    }

    protected function getNormalizedAllowedSorts(): array
    {
        $normalized = [];

        foreach ($this->getRawAllowedSorts() as $key => $value) {
            $normalized[is_int($key) ? $value : $key] = is_int($key) ? null : $value;
        }

        return $normalized;
    }

    protected function getRawAllowedSorts(): array
    {
        $attribute = (new ReflectionClass($this))->getAttributes(Sortable::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->columns;
        }

        return $this->sort;
    }
}
