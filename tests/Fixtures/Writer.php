<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Database\Factories\AuthorFactory;

/**
 * Names its factory with the static $factory property; no WriterFactory exists.
 */
class Writer extends ConsistentModel
{
    protected static string $factory = AuthorFactory::class;

    protected $table = 'authors';
}
