<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable([
    'name',
    'status' => ['eq', 'in'],
    'price' => ['eq' => ['integer', 'nullable'], 'in' => []],
    'released_at' => ['from' => ['date', 'nullable'], 'to' => ['date', 'nullable']],
])]
#[Sortable(['name', 'price'])]
class Product extends ConsistentModel
{
    protected $table = 'products';

    protected $fillable = ['name', 'status', 'price', 'released_at'];
}
