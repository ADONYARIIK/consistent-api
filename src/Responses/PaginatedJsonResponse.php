<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginatedJsonResponse extends JsonResponse
{
    public function __construct(public LengthAwarePaginator $paginator, string $resourceClass = JsonResource::class, ?string $dataContainerName = null, ?string $metaContainerName = null)
    {
        $data[$dataContainerName ?: config('pagination.data_container_name')] = $resourceClass::collection($paginator->getCollection());

        $data[$metaContainerName ?: config('pagination.meta_container_name')] = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'path' => $paginator->path(),
        ];

        parent::__construct($data);
    }
}
