<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

class Sticker extends ConsistentModel
{
    protected $table = 'stickers';

    protected $fillable = ['code', 'name'];
}
