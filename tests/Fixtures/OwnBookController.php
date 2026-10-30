<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Overrides the hooks of ConsistentController as an application's trait for personal modules would, with Ann
 * as the current user: the index lists her books unless the request has X-Admin, store makes her the author
 * and update makes her the editor, whatever the client sends.
 */
class OwnBookController extends BookController
{
    protected function indexQuery(Builder $query, BaseSearchRequest $request): Builder
    {
        return $request->hasHeader('X-Admin') ? $query : $query->whereBelongsTo($this->ann());
    }

    protected function beforeStore(FormRequest $request, Model $model): void
    {
        assert($model instanceof Book);

        $model->author()->associate($this->ann());
    }

    protected function beforeUpdate(FormRequest $request, Model $model): void
    {
        assert($model instanceof Book);

        // The hook runs after fill(): the title from the request is already set, the editor is not
        if ($model->isDirty('title')) {
            $model->editor()->associate($this->ann());
        }
    }

    private function ann(): Author
    {
        return Author::where('name', 'Ann')->firstOrFail();
    }
}
