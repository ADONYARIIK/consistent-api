<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

/**
 * A RelationSort in #[Sortable] does not point to a relation CanSort can order by.
 *
 * FilterableSortableValidator throws it at boot when the relation method is missing, does not return an
 * Eloquent relation, or returns a MorphTo relation. Without the check such a sort would be silently
 * ignored.
 *
 * @package Adonyarik\ConsistentApi\Exceptions
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class InvalidRelationSortException extends RuntimeException
{
    /**
     * Creates the exception for a relation method that does not exist on the model.
     *
     * Usually a typo in the relation name of the RelationSort.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Sort column holding the RelationSort
     * @param  string  $relation  Relation name that has no method
     *
     * @return self The exception to throw
     */
    public static function relationNotFound(string $modelClass, string $column, string $relation): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Sortable] column "%s" as a RelationSort '
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
     * @param  string  $column  Sort column holding the RelationSort
     * @param  string  $relation  Method that is not a relation
     *
     * @return self The exception to throw
     */
    public static function notARelation(string $modelClass, string $column, string $relation): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Sortable] column "%s" as a RelationSort '
                .'pointing to "%s", but that method does not return an Eloquent relation.',
            $modelClass,
            $column,
            $relation
        ));
    }

    /**
     * Creates the exception for a relation type CanSort cannot order by.
     *
     * Every relation type is supported except MorphTo, whose related table depends on the row.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Sort column holding the RelationSort
     * @param  string  $relation  Relation method name
     * @param  string  $relationClass  Class of the relation the method returns
     *
     * @return self The exception to throw
     */
    public static function unsupportedRelation(string $modelClass, string $column, string $relation, string $relationClass): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Sortable] column "%s" as a RelationSort '
                .'pointing to relation "%s" of type [%s], which cannot be sorted by. '
                .'Every relation type is supported except MorphTo, whose related table depends on the row.',
            $modelClass,
            $column,
            $relation,
            $relationClass
        ));
    }
}
