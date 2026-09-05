<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @method static Builder filter(array $filters)
 */
trait CanFilter
{
    protected array $filter = [];

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $query->select($this->getTable() . '.*');

        $query = $this->handleFilter($query, $filters);

        return $query;
    }

    protected function handleFilter(Builder $query, array $filters): Builder
    {
        foreach ($filters as $column => $filter) {
            if (in_array($column, $this->getAllowedFilters(), true)) {
                $query = $query->where(
                    $this->getTable() . '.' . $column,
                    DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE',
                    "%$filter%"
                );
            }
        }
        return $query;
    }

    public function isFilterable(): bool
    {
        return ! empty($this->filter);
    }

    public function getAllowedFilters(): array
    {
        return $this->filter;
    }
}
