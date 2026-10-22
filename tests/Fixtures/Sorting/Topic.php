<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

class Topic extends ConsistentModel
{
    protected $table = 'topics';

    protected $fillable = ['name'];
}
