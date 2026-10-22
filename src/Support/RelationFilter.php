<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

/**
 * Filter definition for a column of a related model.
 *
 * Used as a value in #[Filterable]: 'author' => new RelationFilter('user', 'name') lets
 * filter[author]=ann match the models whose related user has "ann" in the name. CanFilter applies it with
 * whereHas(), so any relation type works, and the relation may be a dotted path through several relations
 * ('author.profile'); the filter takes a single value, not operators. At boot FilterableSortableValidator
 * checks that every relation of the path exists.
 *
 * @package Adonyarik\ConsistentApi\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class RelationFilter
{
    /**
     * Operators a relation filter can take: eq compares with =, like is an escaped substring match.
     */
    public const OPERATORS = ['eq', 'like'];

    /**
     * Defines the relation filter.
     *
     * The values are used as given: relation and column end up in the query, so they must come from code,
     * never from input. Any operator other than eq and like is an error, never a substring match:
     * FilterableSortableValidator rejects it at boot and CanFilter throws when the filter is applied. The
     * rules validate the filter value in BaseSearchRequest (nullable is added); give a non-text column a
     * type rule, such as ['integer'], so a value the database cannot compare is a 422, not a query error.
     *
     * @param  string  $relation  Name of the model's relation method, or a dotted path such as author.profile
     * @param  string  $column  Column of the related model's table
     * @param  string  $operator  'eq' for equality or 'like' (default) for a substring match
     * @param  array<int, mixed>  $rules  Laravel validation rules for the filter value
     *
     * @see RelationFilter::OPERATORS
     */
    public function __construct(
        public string $relation,
        public string $column,
        public string $operator = 'like',
        public array $rules = [],
    ) {}
}
