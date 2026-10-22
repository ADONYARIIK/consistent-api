<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;

class ProductController extends ConsistentController
{
    protected string $resourceClass = ProductResource::class;

    public function index(SearchProductRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Product);
    }
}
