<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

/**
 * Sort definition for a column of a related model.
 *
 * Used as a value in #[Sortable]: 'author' => new RelationSort('user', 'name') lets sort[author]=asc order
 * the models by their related user's name. CanSort joins BelongsTo / HasOne relations and orders
 * BelongsToMany / HasMany relations by their values aggregated per row (PostgreSQL and MySQL / MariaDB
 * only). At boot FilterableSortableValidator rejects missing and other relation types.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class RelationSort
{
    /**
     * Defines the relation sort.
     *
     * The values are used as given: relation and column end up in the query, so they must come from code,
     * never from input.
     *
     * @param  string  $relation  Name of the model's relation method
     * @param  string  $column  Column of the related model's table
     */
    public function __construct(
        public string $relation,
        public string $column
    ) {}
}
