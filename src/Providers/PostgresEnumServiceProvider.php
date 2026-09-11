<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Adonyarik\ConsistentApi\Exceptions\PostgresEnumException;
use Adonyarik\ConsistentApi\Support\PostgresEnumManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;

class PostgresEnumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PostgresEnumManager::class);
    }

    public function boot(): void
    {
        if (! $this->shouldRegisterMacros()) {
            return;
        }

        $this->bootDbMacros();
        $this->bootBlueprintMacros();
        $this->bootGrammarMacros();
    }

    private function bootDbMacros(): void
    {
        $manager = $this->app->make(PostgresEnumManager::class);

        DB::macro('pgEnumCreate', static fn (string $type, array $values) => $manager->createType($type, $values));
        DB::macro('pgEnumDrop', static fn (string $type) => $manager->dropType($type));
        DB::macro('pgEnumConvertColumn', static fn (string $table, string $column, string $type) => $manager->convertColumn($table, $column, $type));
        DB::macro('pgEnumRedefine', static fn (string $type, array $values) => $manager->redefineValues($type, $values));
        DB::macro(
            'pgEnumRedefineWithDefault',
            static fn (string $table, string $column, string $type, array $values, string $default) => $manager->redefineValuesWithDefault($table, $column, $type, $values, $default)
        );
    }

    private function bootBlueprintMacros(): void
    {
        $manager = $this->app->make(PostgresEnumManager::class);

        Blueprint::macro('pgEnumColumn', function (string $column, string $type, array $options = []) use ($manager) {
            /** @var Blueprint $this */
            if (! $manager->typeExists($type)) {
                throw PostgresEnumException::typeMissing($type);
            }

            return $this->addColumn('pgEnum', $column, array_merge($options, ['pgEnumType' => $type]));
        });

        Blueprint::macro('pgEnumColumnNew', function (string $column, string $type, array $values, array $options = []) use ($manager) {
            /** @var Blueprint $this */
            $manager->createType($type, $values);

            return $this->addColumn('pgEnum', $column, array_merge($options, ['pgEnumType' => $type]));
        });

        Blueprint::macro('pgEnumDefault', function (string $column, string $type, string $value) use ($manager) {
            /** @var Blueprint $this */
            if (! $manager->typeExists($type)) {
                throw PostgresEnumException::typeMissing($type);
            }

            if (! in_array($value, $manager->allowedValues($type), true)) {
                throw PostgresEnumException::valueNotAllowed($value, $type);
            }

            $this->addCommand('pgEnumDefault', compact('column', 'value'));
        });
    }

    private function bootGrammarMacros(): void
    {
        Grammar::macro('typePgEnum', static fn (Fluent $column) => $column->get('pgEnumType'));

        Grammar::macro('compilePgEnumDefault', function (Blueprint $blueprint, Fluent $command) {
            return sprintf(
                "alter table %s alter column %s set default '%s'",
                $blueprint->getTable(),
                $command->get('column'),
                str_replace("'", "''", (string) $command->get('value'))
            );
        });
    }

    private function shouldRegisterMacros(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        return $this->isRunningMigrationCommand() || $this->isRunningTestRunner();
    }

    private function isRunningMigrationCommand(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        if (count($argv) < 2) {
            return false;
        }

        if (basename((string) $argv[0]) !== 'artisan') {
            return false;
        }

        return str_starts_with((string) $argv[1], 'migrate');
    }

    private function isRunningTestRunner(): bool
    {
        $bin = basename((string) ($_SERVER['argv'][0] ?? ''));

        return in_array($bin, ['phpunit', 'pest'], true);
    }
}
