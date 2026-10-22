<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Attributes;

use Adonyarik\ConsistentApi\Support\RelationFilter;
use Attribute;

/**
 * Declares model columns that requests can filter by, optionally for some access groups only.
 *
 * Repeatable class attribute read by CanFilter. The column list mixes plain columns ('title'), columns
 * with operators ('status' => ['eq', 'in'], or 'email' => ['eq' => ['email']] with validation rules for
 * the value) and relation filters ('author' => new RelationFilter('user', 'name')). With accesses, the
 * columns exist only for routes that have one of the listed consistentapi.accesses groups. A column may
 * appear in one #[Filterable] of a model only; FilterableSortableValidator checks the declarations at boot.
 *
 * @package Adonyarik\ConsistentApi\Attributes
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Filterable
{
    /**
     * Holds one filter declaration of a model.
     *
     * PHP creates the instance when CanFilter or FilterableSortableValidator reads the attribute through
     * reflection; the values are kept as declared and normalized by CanFilter.
     *
     * @param  array<int|string, mixed>  $columns  Plain column names, column => operators, or column => RelationFilter
     * @param  list<string>  $accesses  Groups from consistentapi.accesses allowed to use these columns; empty — every request
     *
     * @see RelationFilter
     */
    public function __construct(public array $columns, public array $accesses = []) {}
}
