<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['name'])]
#[Sortable(['name'])]
class Tag extends ConsistentModel implements WithoutPaginationModelContract
{
    protected $table = 'tags';

    protected $fillable = ['name'];
}
