<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Illuminate\Database\Eloquent\Builder;
use ReflectionClass;

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
        $attribute = (new ReflectionClass($this))->getAttributes(Sortable::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->columns;
        }

        return $this->sort;
    }
}
