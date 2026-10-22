<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;

class TagController extends ConsistentController
{
    protected string $resourceClass = TagResource::class;

    public function index(SearchTagRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Tag);
    }
}
