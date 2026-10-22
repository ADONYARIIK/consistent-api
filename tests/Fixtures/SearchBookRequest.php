<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchBookRequest extends BaseSearchRequest
{
    protected string $model = Book::class;
}
