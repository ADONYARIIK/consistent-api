<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

class InvalidRelationFilterException extends RuntimeException
{
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
}
