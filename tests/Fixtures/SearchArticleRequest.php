<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchArticleRequest extends BaseSearchRequest
{
    protected string $model = Article::class;
}
