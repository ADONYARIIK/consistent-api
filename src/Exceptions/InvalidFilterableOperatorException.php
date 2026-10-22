<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

/**
 * #[Filterable] declares an operator that config('consistentapi.filters.operators') does not enable.
 *
 * FilterableSortableValidator throws it at boot. Without the check, such an operator would be declared on
 * the model but rejected by BaseSearchRequest for every request.
 *
 * @package Adonyarik\ConsistentApi\Exceptions
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class InvalidFilterableOperatorException extends RuntimeException
{
    /**
     * Creates the exception for the disabled operators of one column.
     *
     * The message lists the operators and suggests enabling them in config or removing them from the
     * attribute.
     *
     * @param  string  $modelClass  Model with the declaration
     * @param  string  $column  Column declaring the operators
     * @param  array<int, string>  $invalidOperators  Operators missing from config('consistentapi.filters.operators')
     *
     * @return self The exception to throw
     */
    public static function forColumn(string $modelClass, string $column, array $invalidOperators): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Filterable] operator(s) [%s] for column "%s" '
                .'that are not enabled in config("consistentapi.filters.operators"). '
                .'Either add them to the config or remove them from the attribute.',
            $modelClass,
            implode(', ', $invalidOperators),
            $column
        ));
    }
}
