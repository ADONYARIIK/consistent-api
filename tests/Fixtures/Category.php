<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Every relation points back to the categories table.
 */
#[Sortable([
    'name',
    'parent' => new RelationSort('parent', 'name'),
    'first_child' => new RelationSort('firstChild', 'name'),
    'children' => new RelationSort('children', 'name'),
    'related' => new RelationSort('related', 'name'),
])]
class Category extends ConsistentModel
{
    protected $table = 'categories';

    protected $fillable = ['name', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function firstChild(): HasOne
    {
        return $this->hasOne(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'category_related', 'category_id', 'related_id');
    }
}
