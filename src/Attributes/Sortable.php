<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Sortable
{
    /**
     * @param  array<int|string, mixed>  $columns
     */
    public function __construct(public array $columns) {}
}
