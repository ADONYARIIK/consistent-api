<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class PostController extends ConsistentController
{
    protected string $resourceClass = PostResource::class;

    public function index(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post);
    }

    public function show(Post $post): JsonResource
    {
        return $this->selectLogic($post);
    }
}
