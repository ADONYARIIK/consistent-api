<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['email'], accesses: ['admin'])]
#[Sortable(['email'], accesses: ['admin'])]
class SecretArticle extends ConsistentModel
{
    protected $table = 'articles';
}
