<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

/**
 * Planned move of one class into a module.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class Move
{
    /**
     * Holds the move.
     *
     * @param  string  $source  File the class is in
     * @param  string  $destination  File the class moves to
     * @param  string  $class  Short class name, the same before and after the move
     * @param  string  $oldClass  Fully qualified name before the move
     * @param  string  $newClass  Fully qualified name after the move
     * @param  string  $namespace  Namespace after the move
     * @param  string  $type  Module subfolder: Models, Controllers, Requests or Resources
     * @param  string  $module  Module name, such as Posts
     * @param  string|null  $note  Why the class went to this module when the name alone does not say it, null otherwise
     */
    public function __construct(
        public readonly string $source,
        public readonly string $destination,
        public readonly string $class,
        public readonly string $oldClass,
        public readonly string $newClass,
        public readonly string $namespace,
        public readonly string $type,
        public readonly string $module,
        public readonly ?string $note = null,
    ) {}
}
