<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

final class RelationSort
{
    public function __construct(
        public string $relation,
        public string $column
    ) {}
}
