<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Filterable
{
    /**
     * @param array<int, string> $columns
     */
    public function __construct(public array $columns)
    {
    }
}
