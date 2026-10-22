<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Relations whose definitions narrow the related rows, and columns that need quoting.
 */
#[Sortable([
    'name',
    'label' => new RelationSort('label', 'displayName'),
    'latest_label' => new RelationSort('latestLabel', 'displayName'),
    'approved_volumes' => new RelationSort('approvedVolumes', 'title'),
    'volume_pages' => new RelationSort('volumes', 'pages'),
])]
class Shelf extends ConsistentModel
{
    protected $table = 'shelves';

    protected $fillable = ['name'];

    public function label(): HasOne
    {
        return $this->hasOne(ShelfLabel::class);
    }

    public function latestLabel(): HasOne
    {
        return $this->hasOne(ShelfLabel::class)->latestOfMany();
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    public function approvedVolumes(): HasMany
    {
        return $this->hasMany(Volume::class)->where('approved', true);
    }
}
