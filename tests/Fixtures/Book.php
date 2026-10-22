<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Filterable;
use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationFilter;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Adonyarik\ConsistentApi\Traits\Credibility;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Declares every operator, so the filter tests enable them all in config('consistentapi.filters.operators').
 * The author and the editor are two relations to the authors table.
 */
#[Filterable([
    'title',
    'status' => ['eq', 'not_eq', 'like', 'in' => ['array', 'max:2'], 'not_in', 'null'],
    'price' => ['eq', 'min' => ['integer', 'nullable'], 'max' => ['integer', 'nullable']],
    'published_at' => ['from' => ['date', 'nullable'], 'to' => ['date', 'nullable'], 'null'],
    'author' => new RelationFilter('author', 'name'),
    'author_exact' => new RelationFilter('author', 'name', 'eq'),
    'tag' => new RelationFilter('tags', 'name'),
    'author_id' => new RelationFilter('author', 'id', 'eq', ['integer']),
])]
#[Sortable([
    'title',
    'price',
    'author' => new RelationSort('author', 'name'),
    'editor' => new RelationSort('editor', 'name'),
    'tags' => new RelationSort('tags', 'name'),
])]
class Book extends ConsistentModel
{
    use Credibility;

    protected $table = 'books';

    protected $fillable = ['title', 'status', 'price', 'published_at', 'author_id', 'editor_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
