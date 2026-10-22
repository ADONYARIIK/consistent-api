<?php

declare(strict_types=1);

namespace Tests\InvalidModels\Mixed;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\Fixtures\Author;

/**
 * Cannot be instantiated to check the relation, so validating it would report a valid relation as broken.
 */
#[Filterable(['author' => new RelationFilter('author', 'name')])]
abstract class AbstractReport extends ConsistentModel
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
