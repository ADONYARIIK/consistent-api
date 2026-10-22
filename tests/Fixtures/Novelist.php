<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Names its factory with #[UseFactory]; no NovelistFactory exists.
 */
#[UseFactory(AuthorFactory::class)]
class Novelist extends ConsistentModel
{
    protected $table = 'authors';
}
