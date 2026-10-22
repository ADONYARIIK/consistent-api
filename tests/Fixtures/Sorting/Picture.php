<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

class Picture extends ConsistentModel
{
    protected $table = 'pictures';

    protected $fillable = ['file'];
}
