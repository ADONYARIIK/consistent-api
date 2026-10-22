<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Attributes\Sortable;
use Adonyarik\ConsistentApi\Models\ConsistentModel;
use Adonyarik\ConsistentApi\Support\RelationSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Sortable(['article' => new RelationSort('article', 'title')])]
class ArticleComment extends ConsistentModel
{
    protected $fillable = ['article_id', 'body'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Exposes the protected *JoinOnce() helpers: $type is inner, left or right.
     */
    public function scopeJoinArticles(Builder $query, string $type): Builder
    {
        return match ($type) {
            'inner' => $this->joinOnce($query, 'articles', 'articles.id', '=', 'article_comments.article_id'),
            'left' => $this->leftJoinOnce($query, 'articles', 'articles.id', '=', 'article_comments.article_id'),
            'right' => $this->rightJoinOnce($query, 'articles', 'articles.id', '=', 'article_comments.article_id'),
        };
    }
}
