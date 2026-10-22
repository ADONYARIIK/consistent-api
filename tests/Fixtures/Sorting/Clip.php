<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Clip extends ConsistentModel
{
    protected $table = 'clips';

    protected $fillable = ['name'];

    public function pictures(): MorphMany
    {
        return $this->morphMany(Picture::class, 'imageable');
    }

    public function topics(): MorphToMany
    {
        return $this->morphToMany(Topic::class, 'topicable');
    }
}
