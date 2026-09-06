<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

class PgEnumException extends \Exception
{
    public static function enumTypeAlreadyExists(string $enumType): self
    {
        return new self(
            "$enumType enum type already exists."
        );
    }

    public static function enumTypeNotFound(string $enumType): self
    {
        return new self(
            "$enumType enum type not found."
        );
    }

    public static function invalidEnumValue(string $value, string $enumType): self
    {
        return new self(
            "Invalid enum value '$value' for type '$enumType'."
        );
    }

    public static function invalidColumnEnumValues(string $table, string $column, array $invalid, array $valid): self
    {
        return new self(
            "Column '{$table}.{$column}' contains invalid values: " . implode(', ', $invalid)
                . '. Valid enum values are: ' . implode(', ', $valid)
        );
    }

    public static function enumTypeInUse(string $enumType): self
    {
        return new self(
            "Cannot drop enum type '$enumType' - it is currently in use."
        );
    }

    public static function enumUsedInMultipleTables(string $enumType, string $allowedTable, array $otherTables): self
    {
        $tablesList = implode(', ', $otherTables);
        return new self(
            "Enum type '$enumType' is used in multiple tables. Expected only '$allowedTable', but also found in: $tablesList. Use pgsqlAlterEnumValues() for shared enums or create separate enum types."
        );
    }
}
