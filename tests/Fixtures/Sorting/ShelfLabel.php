<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

class ShelfLabel extends ConsistentModel
{
    protected $table = 'shelf_labels';

    protected $fillable = ['shelf_id', 'displayName'];
}
