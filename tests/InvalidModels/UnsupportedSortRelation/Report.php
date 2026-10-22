<?php

declare(strict_types=1);

namespace Tests\InvalidModels\UnsupportedSortRelation;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Sortable(['owner' => new RelationSort('owner', 'name')])]
class Report extends ConsistentModel
{
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
