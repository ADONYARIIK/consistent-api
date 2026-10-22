<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

class SearchSecretArticleRequest extends BaseSearchRequest
{
    protected string $model = SecretArticle::class;
}
