<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Shares the pictures and topics tables with Clip through morph relations.
 */
#[Sortable([
    'pictures' => new RelationSort('pictures', 'file'),
    'topics' => new RelationSort('topics', 'name'),
])]
class Gallery extends ConsistentModel
{
    protected $table = 'galleries';

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
