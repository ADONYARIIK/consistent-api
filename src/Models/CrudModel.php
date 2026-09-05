<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Models;

use Illuminate\Database\Eloquent\Model;
use Adonyarik\ConsistentApi\Traits\CanFilter;
use Adonyarik\ConsistentApi\Traits\CanSort;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

class CrudModel extends Model
{
    use CanFilter, CanSort, HasFactory;

    protected $perPage = 10;

    protected static function newFactory(): ?Factory
    {
        $modelName = class_basename(static::class);

        $factoryClass = "Database\\Factories\\{$modelName}Factory";

        if (class_exists($factoryClass)) {
            return $factoryClass::new();
        }

        return null;
    }

    protected function leftJoinOnce(
        Builder $query,
        string $table,
        string $first,
        string $operator,
        string $second
    ): Builder {
        foreach ($query->getQuery()->joins ?? [] as $join) {
            if ($join->table === $table) {
                return $query;
            }
        }

        return $query->leftJoin($table, $first, $operator, $second);
    }

    public function getAllColumns(): array
    {
        return Schema::getColumnListing($this->getTable());
    }
}
