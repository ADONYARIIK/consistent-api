<?php

declare(strict_types=1);

namespace Tests\InvalidModels\DisabledOperator;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title', 'status' => ['eq', 'between', 'near']])]
class Report extends ConsistentModel {}
