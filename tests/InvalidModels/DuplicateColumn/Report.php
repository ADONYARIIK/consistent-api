<?php

declare(strict_types=1);

namespace Tests\InvalidModels\DuplicateColumn;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Sortable(['title'])]
#[Sortable(['title'], accesses: ['admin'])]
class Report extends ConsistentModel {}
