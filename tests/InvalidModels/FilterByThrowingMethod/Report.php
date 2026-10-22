<?php

declare(strict_types=1);

namespace Tests\InvalidModels\FilterByThrowingMethod;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Filterable(['author' => new RelationFilter('author', 'name')])]
class Report extends ConsistentModel
{
    // Needs an argument, so calling it on a fresh model throws
    public function author(string $foreignKey): BelongsTo
    {
        return $this->belongsTo(self::class, $foreignKey);
    }
}
