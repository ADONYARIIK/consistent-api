<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

final class RelationFilter
{
    public function __construct(
        public string $relation,
        public string $column,
        public string $operator = 'like'
    ) {}
}
