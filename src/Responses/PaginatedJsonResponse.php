<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON response with a page of resources and its pagination meta.
 *
 * Shape: {"items": [...], "meta": {"current_page", "last_page", "from", "to", "total", "per_page", "path"}},
 * with the container names taken from consistentapi.pagination unless given. ConsistentController::indexLogic()
 * returns it; it can also be returned from any action that paginates.
 *
 * @package Adonyarik\ConsistentApi\Responses
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class PaginatedJsonResponse extends JsonResponse
{
    /**
     * Builds the response body from the paginator.
     *
     * Wraps the page items with $resourceClass::collection(); the meta block is read from the paginator,
     * so from and to are null on an empty page. Top-level data the collection adds through with() and
     * additional() is merged in, as Laravel does for resource responses. Status 200.
     *
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator  Page to send
     * @param  class-string<JsonResource>  $resourceClass  Resource that renders each item
     * @param  string|null  $dataContainerName  Key of the items; null for config('consistentapi.pagination.data_container_name')
     * @param  string|null  $metaContainerName  Key of the meta block; null for config('consistentapi.pagination.meta_container_name')
     */
    public function __construct(public LengthAwarePaginator $paginator, string $resourceClass = JsonResource::class, ?string $dataContainerName = null, ?string $metaContainerName = null)
    {
        $request = request();
        $collection = $resourceClass::collection(collect($paginator->items()));

        $data = [];
        $data[$dataContainerName ?: config('consistentapi.pagination.data_container_name', 'items')] = $collection->resolve($request);

        $data[$metaContainerName ?: config('consistentapi.pagination.meta_container_name', 'meta')] = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'path' => $paginator->path(),
        ];

        parent::__construct(array_merge_recursive($data, $collection->with($request), $collection->additional));
    }
}
