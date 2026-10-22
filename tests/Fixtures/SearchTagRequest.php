<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchTagRequest extends BaseSearchRequest
{
    protected string $model = Tag::class;
}
