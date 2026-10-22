<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use Adonyarik\ConsistentApi\Support\RelationFilter;
use RuntimeException;

/**
 * A RelationFilter in #[Filterable] does not point to a relation or has an unknown operator.
 *
 * FilterableSortableValidator throws it at boot when the relation method is missing, does not return an
 * Eloquent relation, or the operator is not one RelationFilter supports, mistakes that would otherwise fail
 * on the first request using the filter. CanFilter throws it for an unknown operator too, where the
 * validation does not run.
 *
 * @package Adonyarik\ConsistentApi\Exceptions
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class InvalidRelationFilterException extends RuntimeException
{
    /**
     * Creates the exception for a relation method that does not exist on the model.
     *
     * Usually a typo in the relation name of the RelationFilter.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Filter column holding the RelationFilter
     * @param  string  $relation  Relation name that has no method
     *
     * @return self The exception to throw
     */
    public static function relationNotFound(string $modelClass, string $column, string $relation): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Filterable] column "%s" as a RelationFilter '
                .'pointing to relation "%s", but no such method exists on the model. '
                .'Make sure the relation method is defined and spelled correctly.',
            $modelClass,
            $column,
            $relation
        ));
    }

    /**
     * Creates the exception for a method that does not return an Eloquent relation.
     *
     * Also used when calling the method on a fresh model throws.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Filter column holding the RelationFilter
     * @param  string  $relation  Method that is not a relation
     *
     * @return self The exception to throw
     */
    public static function notARelation(string $modelClass, string $column, string $relation): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Filterable] column "%s" as a RelationFilter '
                .'pointing to "%s", but that method does not return an Eloquent relation.',
            $modelClass,
            $column,
            $relation
        ));
    }

    /**
     * Creates the exception for an operator RelationFilter does not support.
     *
     * Usually a typo such as 'equal' or '='. The filter is never applied with a guessed operator, so a
     * mistyped equality cannot turn into a substring match.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Filter column holding the RelationFilter
     * @param  string  $operator  Operator that is not supported
     *
     * @return self The exception to throw
     *
     * @see RelationFilter::OPERATORS
     */
    public static function unknownOperator(string $modelClass, string $column, string $operator): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Filterable] column "%s" as a RelationFilter with operator "%s". '
                .'Use "eq" for equality or "like" for a substring match.',
            $modelClass,
            $column,
            $operator
        ));
    }
}
