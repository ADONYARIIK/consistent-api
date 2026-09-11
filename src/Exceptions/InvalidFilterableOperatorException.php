<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

class InvalidFilterableOperatorException extends RuntimeException
{
    public static function forColumn(string $modelClass, string $column, array $invalidOperators): self
    {
        return new self(sprintf(
            'Model [%s] declares #[Filterable] operator(s) [%s] for column "%s" '
                .'that are not enabled in config("filters.operators"). '
                .'Either add them to the config or remove them from the attribute.',
            $modelClass,
            implode(', ', $invalidOperators),
            $column
        ));
    }
}
