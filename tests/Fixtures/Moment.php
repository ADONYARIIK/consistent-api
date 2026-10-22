<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;

/**
 * A date column and a datetime column with microseconds, for the from / to operators.
 */
#[Filterable([
    'day' => ['from', 'to'],
    'happened_at' => ['from', 'to'],
])]
class Moment extends ConsistentModel
{
    public $timestamps = false;

    protected $table = 'moments';

    protected $fillable = ['day', 'happened_at'];
}
