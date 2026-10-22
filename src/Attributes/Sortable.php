<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Attributes;

use Adonyarik\ConsistentApi\Support\RelationSort;
use Attribute;

/**
 * Declares model columns that requests can sort by, optionally for some access groups only.
 *
 * Repeatable class attribute read by CanSort. The column list mixes plain columns of the model's table
 * ('created_at') and relation sorts ('author' => new RelationSort('user', 'name')). With accesses, the
 * columns exist only for routes that have one of the listed consistentapi.accesses groups. A column may
 * appear in one #[Sortable] of a model only; FilterableSortableValidator checks the declarations at boot.
 *
 * @package Adonyarik\ConsistentApi\Attributes
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Sortable
{
    /**
     * Holds one sort declaration of a model.
     *
     * PHP creates the instance when CanSort or FilterableSortableValidator reads the attribute through
     * reflection; the values are kept as declared and normalized by CanSort.
     *
     * @param  array<int|string, mixed>  $columns  Plain column names, or column => RelationSort
     * @param  list<string>  $accesses  Groups from consistentapi.accesses allowed to use these columns; empty — every request
     *
     * @see RelationSort
     */
    public function __construct(public array $columns, public array $accesses = []) {}
}
