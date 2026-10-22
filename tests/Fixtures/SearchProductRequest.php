<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchProductRequest extends BaseSearchRequest
{
    protected string $model = Product::class;
}
