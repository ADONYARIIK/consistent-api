<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchPostRequest extends BaseSearchRequest
{
    protected string $model = Post::class;
}
