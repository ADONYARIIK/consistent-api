<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sorting;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft deleted; its stickers are joined through string codes instead of the primary keys.
 */
#[Sortable(['title', 'stickers' => new RelationSort('stickers', 'name')])]
class Volume extends ConsistentModel
{
    use SoftDeletes;

    protected $table = 'volumes';

    protected $fillable = ['shelf_id', 'code', 'title', 'pages', 'approved'];

    public function stickers(): BelongsToMany
    {
        return $this->belongsToMany(Sticker::class, 'volume_sticker', 'volume_code', 'sticker_code', 'code', 'code');
    }
}
