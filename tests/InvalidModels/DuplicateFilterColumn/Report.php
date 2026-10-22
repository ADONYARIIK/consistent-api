<?php

declare(strict_types=1);

namespace Tests\InvalidModels\DuplicateFilterColumn;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title'])]
#[Filterable(['title' => ['eq'], 'email'], accesses: ['admin'])]
#[Sortable(['title'])]
#[Sortable(['title'], accesses: ['admin'])]
class Report extends ConsistentModel {}
