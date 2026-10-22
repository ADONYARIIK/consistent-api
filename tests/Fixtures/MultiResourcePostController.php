<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Http\JsonResponse;

class MultiResourcePostController extends PostController
{
    protected array $resourceClasses = [
        'index' => PostLightResource::class,
        'adminIndex' => PostAdminResource::class,
    ];

    public function adminIndex(SearchPostRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Post);
    }
}
