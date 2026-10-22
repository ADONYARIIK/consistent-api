<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title'])]
#[Sortable(['id', 'title'])]
class Post extends ConsistentModel
{
    protected $table = 'posts';

    protected $fillable = ['title'];
}
