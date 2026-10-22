<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Support;

/**
 * Sorts and renders use statements the way Pint's ordered_imports expects.
 *
 * Shared by ModuleGenerator, which writes new files, and consistent:rebuild, which adds imports to existing
 * ones, so both produce files Pint leaves alone.
 *
 * @package Adonyarik\ConsistentApi\Console\Support
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class PhpImports
{
    /**
     * use statements for the classes, sorted and without duplicates.
     *
     * Names are compared case-insensitively with namespace separators as spaces, as Pint does.
     *
     * @param  array<int|string, string>  $classes  Fully qualified class names; a string key is the alias when it differs from the short name
     *
     * @return list<string> One statement per class, such as use App\Models\Post; or use App\Models\Post as Article;
     */
    public static function statements(array $classes): array
    {
        $statements = [];

        foreach ($classes as $alias => $class) {
            $class = ltrim($class, '\\');
            $short = substr((string) strrchr('\\'.$class, '\\'), 1);
            $statements[] = is_string($alias) && strcasecmp($alias, $short) !== 0
                ? sprintf('use %s as %s;', $class, $alias)
                : sprintf('use %s;', $class);
        }

        return self::sort($statements);
    }

    /**
     * Sorts use statements in Pint order and drops duplicates.
     *
     * @param  list<string>  $statements  Statements such as use App\Models\Post;
     *
     * @return list<string> The statements in Pint order
     */
    public static function sort(array $statements): array
    {
        $statements = array_values(array_unique($statements));
        $key = fn (string $statement): string => strtolower(str_replace('\\', ' ', trim(substr(trim($statement), 4), " \t;")));

        usort($statements, fn (string $left, string $right): int => strcmp($key($left), $key($right)));

        return $statements;
    }
}
