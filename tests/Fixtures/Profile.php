<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Models\ConsistentModel;

class Profile extends ConsistentModel
{
    protected $table = 'profiles';

    protected $fillable = ['author_id', 'nickname'];
}
