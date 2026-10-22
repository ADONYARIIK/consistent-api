<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

/**
 * Invalid access restriction in a #[Filterable] / #[Sortable] declaration.
 *
 * FilterableSortableValidator throws it at boot when an attribute names an access group missing from
 * consistentapi.accesses, or when a column is declared in more than one attribute of the same kind. Either
 * mistake would otherwise make columns silently unavailable or shadowed.
 *
 * @package Adonyarik\ConsistentApi\Exceptions
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class InvalidAccessException extends RuntimeException
{
    /**
     * Creates the exception for an access group missing from consistentapi.accesses.
     *
     * The message names the model, the attribute and the group, and explains how to publish the config.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $attribute  Attribute short name: Filterable or Sortable
     * @param  string  $access  Group name that is not defined
     *
     * @return self The exception to throw
     */
    public static function unknownAccess(string $modelClass, string $attribute, string $access): self
    {
        return new self(sprintf(
            'Model [%s] restricts #[%s] columns to access "%s", but no such group is defined in consistentapi.accesses (config/consistentapi.php). '
                .'Declare it there (publish the file with `php artisan vendor:publish --tag=consistent-api-config`).',
            $modelClass,
            $attribute,
            $access
        ));
    }

    /**
     * Creates the exception for a column declared in more than one attribute of the same kind.
     *
     * A column belongs to one #[Filterable] (or one #[Sortable]) of a model, so its access restriction is
     * unambiguous.
     *
     * @param  string  $modelClass  Model with the declarations
     * @param  string  $attribute  Attribute short name: Filterable or Sortable
     * @param  string  $column  Column declared more than once
     *
     * @return self The exception to throw
     */
    public static function duplicateColumn(string $modelClass, string $attribute, string $column): self
    {
        return new self(sprintf(
            'Model [%s] declares column "%s" in more than one #[%s]; each column may appear only once.',
            $modelClass,
            $column,
            $attribute
        ));
    }
}
