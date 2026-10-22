<?php

declare(strict_types=1);

namespace Tests\InvalidModels\DocumentedModel;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

/**
 * This class represents a report; the namespace keyword in this comment does not count either.
 */
#[Filterable(['title'], accesses: ['unknown'])]
final class Report extends ConsistentModel {}
