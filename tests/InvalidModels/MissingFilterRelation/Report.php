<?php

declare(strict_types=1);

namespace Tests\InvalidModels\MissingFilterRelation;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;

#[Filterable(['author' => new RelationFilter('author', 'name')])]
class Report extends ConsistentModel {}
