<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Console\Rebuild;

use RuntimeException;

/**
 * A reason consistent:rebuild stops before changing any file.
 *
 * The command prints the message and fails; it is an expected outcome, not a crash.
 *
 * @package Adonyarik\ConsistentApi\Console\Rebuild
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
final class RebuildException extends RuntimeException {}
