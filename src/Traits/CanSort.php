<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * @method static Builder sort(array $columns)
 */
trait CanSort
{
    protected array $sort = [];

    public function scopeSort(Builder $query, array $columns): Builder
    {
        foreach ($columns as $column => $direction) {
            if (in_array($column, $this->getAllowedSorts(), true)) {
                $query->orderBy($this->getTable() . '.' . $column, $direction);
            }
        }

        return $query;
    }

    public function isSortable(): bool
    {
        return ! empty($this->sort);
    }

    public function getAllowedSorts(): array
    {
        return $this->sort;
    }
}
