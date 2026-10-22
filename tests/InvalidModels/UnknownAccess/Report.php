<?php

declare(strict_types=1);

namespace Tests\InvalidModels\UnknownAccess;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title'], accesses: ['unknown'])]
class Report extends ConsistentModel {}
