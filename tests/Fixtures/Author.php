<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Sortable([
    'name',
    'books' => new RelationSort('books', 'title'),
    'nickname' => new RelationSort('profile', 'nickname'),
])]
class Author extends ConsistentModel
{
    protected $table = 'authors';

    protected $fillable = ['name'];

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}
