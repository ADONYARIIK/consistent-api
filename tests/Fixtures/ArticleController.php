<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;

class ArticleController extends ConsistentController
{
    protected string $resourceClass = ArticleResource::class;

    public function index(SearchArticleRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Article);
    }

    public function secretIndex(SearchSecretArticleRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new SecretArticle);
    }
}
