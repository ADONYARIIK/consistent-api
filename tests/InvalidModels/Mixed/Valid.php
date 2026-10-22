<?php

declare(strict_types=1);

namespace Tests\InvalidModels\Mixed;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

#[Filterable(['title'])]
class Valid extends ConsistentModel {}
