<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Support;

use Adonyarik\ConsistentApi\Exceptions\PostgresEnumException;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates every raw-SQL interaction needed to manage native
 * PostgreSQL enum types from Laravel migrations. Kept separate from
 * the service provider so it can be unit tested / swapped out on its own.
 */
class PostgresEnumManager
{
    public function typeExists(string $type): bool
    {
        return (bool) DB::selectOne(
            'select 1 from pg_type where typname = ?',
            [$type]
        );
    }

    public function allowedValues(string $type): array
    {
        $rows = DB::select(
            'select e.enumlabel as label
            from pg_enum e
            inner join pg_type t on t.oid = e.enumtypid
            where t.typname = ?
            order by e.enumsortorder',
            [$type]
        );

        return array_map(static fn ($row) => $row->label, $rows);
    }

    public function columnsUsingType(string $type): array
    {
        return DB::select(
            'select c.relname as table_name, a.attname as column_name
            from pg_type t
            inner join pg_attribute a on a.atttypeid = t.oid
            inner join pg_class c on c.oid = a.attrelid
            inner join pg_namespace n on n.oid = c.relnamespace
            where t.typename = ? and c.relkind = \'r\'',
            [$type]
        );
    }

    public function createType(string $type, array $values): void
    {
        if ($this->typeExists($type)) {
            throw PostgresEnumException::typeAlreadyExists($type);
        }

        DB::unprepared($this->buildCreateStatement($type, $values));
    }

    public function dropType(string $type): void
    {
        if (! $this->typeExists($type)) {
            throw PostgresEnumException::typeMissing($type);
        }

        if (! empty($this->columnsUsingType($type))) {
            throw PostgresEnumException::typeStillReferenced($type);
        }

        DB::unprepared('drop type '.$this->quoteIdentifier($type));
    }

    public function convertColumn(string $table, string $column, string $type): void
    {
        if (! $this->typeExists($type)) {
            throw PostgresEnumException::typeMissing($type);
        }

        $allowed = $this->allowedValues($type);
        $offending = $this->valuesOutsideAllowedSet($table, $column, $allowed);

        if (! empty($offending)) {
            throw PostgresEnumException::columnHasInvalidValues($table, $column, $offending, $allowed);
        }

        $this->castColumnToType($table, $column, $type);
    }

    public function redefineValues(string $type, array $newValues): void
    {
        if (! $this->typeExists($type)) {
            throw PostgresEnumException::typeMissing($type);
        }

        $affected = $this->columnsUsingType($type);
        $defaults = $this->currentDefaults($affected);

        DB::transaction(function () use ($type, $newValues, $affected, $defaults) {
            $this->dropDefaultsFor($affected, $defaults);

            $staging = $type.'_staging';
            DB::unprepared('alter type '.$this->quoteIdentifier($type).' rename to '.$this->quoteIdentifier($staging));
            DB::unprepared($this->buildCreateStatement($type, $newValues));

            foreach ($affected as $reference) {
                $this->castColumnToType($reference->table_name, $reference->column_name, $type);
            }

            $this->restoreDefaults($affected, $defaults);

            DB::unprepared('drop type if exists '.$this->quoteIdentifier($staging));
        });
    }

    public function setColumnDefault(string $table, string $column, string $type, string $value): void
    {
        if (! $this->typeExists($type)) {
            throw PostgresEnumException::typeMissing($type);
        }

        if (! in_array($value, $this->allowedValues($type), true)) {
            throw PostgresEnumException::valueNotAllowed($value, $type);
        }

        DB::unprepared(sprintf(
            'alter table %s alter column %s set default %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $this->quoteLiteral($value)
        ));
    }

    public function redefineValuesWithDefault(string $table, string $column, string $type, array $newValues, string $defaultValue): void
    {
        if (! $this->typeExists($type)) {
            throw PostgresEnumException::typeMissing($type);
        }

        if (! in_array($defaultValue, $newValues, true)) {
            throw PostgresEnumException::valueNotAllowed($defaultValue, $type);
        }

        $affected = $this->columnsUsingType($type);
        $otherTables = array_values(array_filter(
            $affected,
            static fn ($reference) => $reference->table_name !== $table
        ));

        if (! empty($otherTables)) {
            throw PostgresEnumException::typeSharedAcrossTables(
                $type,
                $table,
                array_map(
                    static fn ($reference) => "{$reference->table_name}.{$reference->column_name}",
                    $otherTables
                )
            );
        }

        DB::transaction(function () use ($table, $column, $type, $newValues, $defaultValue) {
            DB::unprepared(sprintf(
                'alter table %s alter column %s drop default',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column)
            ));

            $staging = $type.'_staging';
            DB::unprepared('alter type '.$this->quoteIdentifier($type).' rename to '.$this->quoteIdentifier($staging));
            DB::unprepared($this->buildCreateStatement($type, $newValues));

            $this->castColumnToType($table, $column, $type);
            $this->setColumnDefault($table, $column, $type, $defaultValue);

            DB::unprepared('drop type if exists '.$this->quoteIdentifier($staging));
        });
    }

    private function buildCreateStatement(string $type, array $values): string
    {
        $escaped = array_map(fn ($value) => $this->quoteLiteral($value), $values);

        return sprintf('create type %s as enum (%s)', $this->quoteIdentifier($type), implode(', ', $escaped));
    }

    private function castColumnToType(string $table, string $column, string $type): void
    {
        $t = $this->quoteIdentifier($table);
        $c = $this->quoteIdentifier($column);
        $ty = $this->quoteIdentifier($type);

        DB::unprepared("alter table {$t} alter column {$c} type {$ty} using {$c}::text::{$ty}");
    }

    private function valuesOutsideAllowedSet(string $table, string $column, array $allowed): array
    {
        if (empty($allowed)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
        $quotedColumn = $this->quoteIdentifier($column);
        $quotedTable = $this->quoteIdentifier($table);

        $rows = DB::select(
            "select distinct {$quotedColumn} as value
            from {$quotedTable}
            where {$quotedColumn} is not null
            and {$quotedColumn}::text not in ({$placeholders})",
            $allowed
        );

        return array_map(static fn ($row) => $row->value, $rows);
    }

    private function currentDefaults(array $columns): array
    {
        $defaults = [];

        foreach ($columns as $reference) {
            $row = DB::selectOne(
                'select pg_get_expr(d.adbin, d.adrelid) as expression
                from pg_attrdef d
                inner join pg_class c on c.oid = d.adrelid
                inner join pg_attribute a on a.attrelid = c.oid and a.attnum = d.adnum
                where c.relname = ? and a.attname = ?',
                [$reference->table_name, $reference->column_name]
            );

            if ($row && $row->expression !== null) {
                $defaults["{$reference->table_name}.{$reference->column_name}"] = $row->expression;
            }
        }

        return $defaults;
    }

    private function dropDefaultsFor(array $columns, array $defaults): void
    {
        foreach ($columns as $reference) {
            if (isset($defaults["{$reference->table_name}.{$reference->column_name}"])) {
                DB::unprepared(sprintf(
                    'alter table %s alter column %s drop default',
                    $this->quoteIdentifier($reference->table_name),
                    $this->quoteIdentifier($reference->column_name)
                ));
            }
        }
    }

    private function restoreDefaults(array $columns, array $defaults): void
    {
        foreach ($columns as $reference) {
            $key = "{$reference->table_name}.{$reference->column_name}";

            if (isset($defaults[$key])) {
                DB::unprepared(sprintf(
                    'alter table %s alter column %s set default %s',
                    $this->quoteIdentifier($reference->table_name),
                    $this->quoteIdentifier($reference->column_name),
                    $defaults[$key]
                ));
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
