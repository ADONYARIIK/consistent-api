<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Adonyarik\ConsistentApi\Exceptions\PgEnumException;
use Illuminate\Database\Grammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;

class PgEnumServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!$this->isMigrationContext()) {
            return;
        }

        $this->registerDbMacros();
        $this->registerBlueprintMacros();
        $this->registerGrammarMacros();
    }

    private function registerDbMacros(): void
    {
        DB::macro('pgsqlCreateEnumType', function (string $type, array $values): void {
            $exists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$type]);
            if ($exists) {
                throw PgEnumException::enumTypeAlreadyExists($type);
            }
            $vals = implode("', '", $values);
            DB::unprepared("CREATE TYPE $type AS ENUM ('$vals');");
        });

        DB::macro('pgsqlChangeEnum', function (string $tbl, string $col, string $enumType): void {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$enumType]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($enumType);
            }
            $enumVals = DB::select('SELECT e.enumlabel as value FROM pg_enum e JOIN pg_type t ON e.enumtypid = t.oid WHERE t.typname = ? ORDER BY e.enumsortorder', [$enumType]);
            $valid = array_column($enumVals, 'value');
            $invalid = DB::select("SELECT DISTINCT {$col} as value FROM {$tbl} WHERE {$col} IS NOT NULL AND {$col}::text NOT IN (" . str_repeat('?,', count($valid) - 1) . '?)', $valid);
            if (!empty($invalid)) {
                $bad = array_column($invalid, 'value');
                throw PgEnumException::invalidColumnEnumValues($tbl, $col, $bad, $valid);
            }
            DB::unprepared("ALTER TABLE $tbl ALTER COLUMN $col TYPE $enumType USING {$col}::text::{$enumType};");
        });

        DB::macro('pgsqlAlterEnumValues', function (string $enumType, array $enumValues): void {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$enumType]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($enumType);
            }
            $vals = implode("', '", $enumValues);
            $tables = DB::select("SELECT DISTINCT n.nspname AS schema_name, c.relname AS table_name, a.attname AS column_name FROM pg_type t JOIN pg_enum e ON t.oid = e.enumtypid JOIN pg_catalog.pg_attribute a ON a.atttypid = t.oid JOIN pg_class c ON a.attrelid = c.oid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE t.typname = '{$enumType}' and c.relkind = 'r';");
            $defaults = [];
            foreach ($tables as $t) {
                $def = DB::selectOne("SELECT pg_get_expr(d.adbin, d.adrelid) AS default_value FROM pg_attrdef d JOIN pg_class c ON d.adrelid = c.oid JOIN pg_namespace n ON c.relnamespace = n.oid WHERE c.relname = '{$t->table_name}' AND d.adnum = (SELECT attnum FROM pg_attribute WHERE attrelid = c.oid AND attname = '{$t->column_name}');");
                if ($def && $def->default_value) {
                    $defaults["{$t->table_name}.{$t->column_name}"] = $def->default_value;
                }
            }
            DB::transaction(function () use ($enumType, $vals, $tables, $defaults) {
                foreach ($tables as $t) {
                    $key = "{$t->table_name}.{$t->column_name}";
                    if (isset($defaults[$key])) {
                        DB::unprepared("ALTER TABLE {$t->table_name} ALTER COLUMN {$t->column_name} DROP DEFAULT;");
                    }
                }
                DB::unprepared("ALTER TYPE $enumType RENAME TO {$enumType}_enum_old;");
                DB::unprepared("CREATE TYPE $enumType AS ENUM('$vals');");
                foreach ($tables as $t) {
                    DB::unprepared("ALTER TABLE {$t->table_name} ALTER COLUMN {$t->column_name} TYPE $enumType USING {$t->column_name}::text::{$enumType};");
                }
                foreach ($tables as $t) {
                    $key = "{$t->table_name}.{$t->column_name}";
                    if (isset($defaults[$key])) {
                        DB::unprepared("ALTER TABLE {$t->table_name} ALTER COLUMN {$t->column_name} SET DEFAULT {$defaults[$key]};");
                    }
                }
                DB::unprepared("DROP TYPE IF EXISTS {$enumType}_enum_old;");
            });
        });

        DB::macro('pgsqlDropEnumType', function (string $type): void {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$type]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($type);
            }
            $inUse = DB::selectOne('SELECT 1 FROM pg_attribute a JOIN pg_type t ON a.atttypid = t.oid WHERE t.typname = ?', [$type]);
            if ($inUse) {
                throw PgEnumException::enumTypeInUse($type);
            }
            DB::unprepared("DROP TYPE $type");
        });

        DB::macro('pgsqlChangeEnumWithDefault', function (string $tbl, string $col, string $enumType, array $enumVals, string $defVal): void {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$enumType]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($enumType);
            }
            if (!in_array($defVal, $enumVals, true)) {
                throw PgEnumException::invalidEnumValue($defVal, $enumType);
            }
            $tables = DB::select("SELECT DISTINCT c.relname AS table_name, a.attname AS column_name FROM pg_type t JOIN pg_catalog.pg_attribute a ON a.atttypid = t.oid JOIN pg_class c ON a.attrelid = c.oid JOIN pg_namespace n ON c.relnamespace = n.oid WHERE t.typname = ? AND c.relkind = 'r'", [$enumType]);
            $others = [];
            foreach ($tables as $info) {
                if ($info->table_name !== $tbl) {
                    $others[] = $info->table_name . '.' . $info->column_name;
                }
            }
            if (!empty($others)) {
                throw PgEnumException::enumUsedInMultipleTables($enumType, $tbl, $others);
            }
            $vals = implode("', '", $enumVals);
            $def = DB::selectOne("SELECT pg_get_expr(d.adbin, d.adrelid) AS default_value FROM pg_attrdef d JOIN pg_class c ON d.adrelid = c.oid JOIN pg_namespace n ON c.relnamespace = n.oid WHERE c.relname = '{$tbl}' AND d.adnum = (SELECT attnum FROM pg_attribute WHERE attrelid = c.oid AND attname = '{$col}');");
            DB::transaction(function () use ($enumType, $vals, $tbl, $col, $defVal, $def) {
                if ($def) {
                    DB::unprepared("ALTER TABLE {$tbl} ALTER COLUMN {$col} DROP DEFAULT;");
                }
                DB::unprepared("ALTER TYPE $enumType RENAME TO {$enumType}_old;");
                DB::unprepared("CREATE TYPE $enumType AS ENUM('$vals');");
                DB::unprepared("ALTER TABLE {$tbl} ALTER COLUMN {$col} TYPE $enumType USING {$col}::text::{$enumType};");
                DB::unprepared("ALTER TABLE {$tbl} ALTER COLUMN {$col} SET DEFAULT '{$defVal}';");
                DB::unprepared("DROP TYPE IF EXISTS {$enumType}_old;");
            });
        });
    }

    private function registerBlueprintMacros(): void
    {
        /**
         * @this \Illuminate\Database\Schema\Blueprint
         */
        Blueprint::macro('pgsqlEnum', function ($col, $enumType, array $opts = []) {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$enumType]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($enumType);
            }
            return $this->addColumn('enumeration', $col, ['pg_enum' => $enumType, ...$opts]);
        });

        /**
         * @this \Illuminate\Database\Schema\Blueprint
         */
        Blueprint::macro('pgsqlCreateEnum', function ($col, $enumType, array $enumVals, array $opts = []) {
            DB::pgsqlCreateEnumType($enumType, $enumVals);
            return $this->addColumn('enumeration', $col, ['pg_enum' => $enumType, ...$opts]);
        });

        /**
         * @this \Illuminate\Database\Schema\Blueprint
         */
        Blueprint::macro('pgsqlSetEnumDefault', function ($col, $enumType, $defVal) {
            $enumExists = DB::selectOne('SELECT 1 FROM pg_type WHERE typname = ?', [$enumType]);
            if (!$enumExists) {
                throw PgEnumException::enumTypeNotFound($enumType);
            }
            $enumVals = DB::select('SELECT e.enumlabel as value FROM pg_enum e JOIN pg_type t ON e.enumtypid = t.oid WHERE t.typname = ? ORDER BY e.enumsortorder', [$enumType]);
            $valid = array_column($enumVals, 'value');
            if (!in_array($defVal, $valid, true)) {
                throw PgEnumException::invalidEnumValue($defVal, $enumType);
            }
            $this->addCommand('pgsqlSetEnumDefault', compact('col', 'defVal'));
        });
    }

    private function registerGrammarMacros(): void
    {
        Grammar::macro('typeEnumeration', function (Fluent $column) {
            return $column->get('pg_enum');
        });

        Grammar::macro('compilePgsqlSetEnumDefault', function (Blueprint $blueprint, Fluent $command) {
            $table = $blueprint->getTable();
            $col = $command->col ?? $command->column;
            $defVal = $command->defVal ?? $command->defaultValue;
            return "ALTER TABLE {$table} ALTER COLUMN {$col} SET DEFAULT '{$defVal}'";
        });
    }

    private function isMigrationContext(): bool
    {
        if (!$this->app->runningInConsole()) {
            return false;
        }
        $argv = $_SERVER['argv'] ?? [];
        if (empty($argv)) {
            return false;
        }
        $bin = basename((string)($argv[0] ?? ''));
        $cmd = (string)($argv[1] ?? '');
        if (in_array($bin, ['pest', 'phpunit'], true)) {
            return true;
        }
        if ($bin !== 'artisan') {
            return false;
        }
        if (preg_match('/^migrate(?:$|:[a-z_]+$)/i', $cmd)) {
            return true;
        }
        return false;
    }
}
