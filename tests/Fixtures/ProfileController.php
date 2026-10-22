<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lists a model without #[Filterable] / #[Sortable] through a search request without $model.
 */
class ProfileController extends ConsistentController
{
    protected string $resourceClass = JsonResource::class;

    public function index(PlainSearchRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Profile);
    }
}
