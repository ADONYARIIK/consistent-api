<?php

declare(strict_types=1);

namespace Tests\InvalidModels\DisabledRuleOperator;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['price' => ['eq' => ['integer'], 'min' => ['integer']]])]
class Report extends ConsistentModel {}
