<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Exceptions;

use InvalidArgumentException;

/**
 * Invalid options of consistent:create, all collected at once.
 *
 * ModuleBlueprintFactory::make() throws it with every problem it found, so the user can fix all options
 * in one go; CreateModuleCommand prints each entry of $errors and exits with a failure code.
 *
 * @package Adonyarik\ConsistentApi\Exceptions
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class InvalidModuleOptionsException extends InvalidArgumentException
{
    /**
     * Creates the exception from the collected errors.
     *
     * The exception message joins the errors with line breaks; $errors keeps them separate.
     *
     * @param  list<string>  $errors  One message per invalid option value
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }
}
