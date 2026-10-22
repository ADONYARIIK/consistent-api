<?php

declare(strict_types=1);

namespace Tests\InvalidModels\UnknownRelationFilterOperator;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\Fixtures\Author;

/**
 * Meant equality, but 'equal' is not an operator: it must fail, not become a substring match.
 */
#[Filterable(['author' => new RelationFilter('author', 'name', 'equal')])]
class Report extends ConsistentModel
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
