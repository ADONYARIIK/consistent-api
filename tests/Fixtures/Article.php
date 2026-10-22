<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title'])]
#[Filterable(['email' => ['eq' => ['email']]], accesses: ['admin'])]
#[Sortable(['title'])]
#[Sortable(['email'], accesses: ['admin'])]
class Article extends ConsistentModel
{
    protected $table = 'articles';

    protected $fillable = ['title', 'email'];
}
