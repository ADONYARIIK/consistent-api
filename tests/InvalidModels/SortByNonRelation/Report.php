<?php

declare(strict_types=1);

namespace Tests\InvalidModels\SortByNonRelation;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;

#[Sortable(['author' => new RelationSort('author', 'name')])]
class Report extends ConsistentModel
{
    public function author(): string
    {
        return 'not a relation';
    }
}
