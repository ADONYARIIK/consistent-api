<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use RuntimeException;

class PostgresEnumException extends RuntimeException
{
    public static function typeAlreadyExists(string $type): self
    {
        return new self("PostgreSQL enum type [{$type}] already exists.");
    }

    public static function typeMissing(string $type): self
    {
        return new self("PostgreSQL enum type [{$type}] was not found.");
    }

    public static function columnHasInvalidValues(string $table, string $column, array $offending, array $allowed): self
    {
        $offendingList = implode(', ', $offending);
        $allowedList = implode(', ', $allowed);

        return new self(
            "Cannot convert column [{$table}.{$column}] to enum: found values [{$offendingList}] ".
            "that are not part of the allowed set [{$allowedList}]."
        );
    }

    public static function valueNotAllowed(string $value, string $type): self
    {
        return new self("Value [{$value}] is not part of enum type [{$type}].");
    }

    public static function typeStillReferenced(string $type): self
    {
        return new self("PostgreSQL enum type [{$type}] is still used by one or more columns and cannot be dropped.");
    }

    public static function typeSharedAcrossTables(string $type, string $expectedTable, array $others): self
    {
        $othersList = implode(', ', $others);

        return new self(
            "Enum type [{$type}] is used by other columns besides [{$expectedTable}]: {$othersList}. ".
            'Refusing to attach a single default to a shared type.'
        );
    }
}
