<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Controllers;

use Adonyarik\ConsistentApi\Contracts\WithoutPaginationModelContract;
use Illuminate\Database\Eloquent\Model;
use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;
use Adonyarik\ConsistentApi\Responses\PaginatedJsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Response;

class CrudController extends Controller
{
    protected string $resourceClass;

    /**
     * @var string[]
     */
    protected array $relationFunctions = [];

    public function indexLogic(BaseSearchRequest $request, Model $model): JsonResponse
    {
        $filters = $request->safe()->except('id');

        $query = $this->buildFilterSortQuery($filters, $model);

        if ($model instanceof WithoutPaginationModelContract && ! $request->boolean('paginate', true)) {
            return new JsonResponse([
                config('pagination.data_container_name') => $this->resourceClass::collection($query->get())->resolve($request),
            ]);
        }
        $items = $query->paginate($request->input('perpage'));

        return new PaginatedJsonResponse(
            $items,
            $this->resourceClass
        );
    }

    public function selectLogic(Model $model): JsonResource
    {
        foreach ($this->relationFunctions as $relationFunction) {
            $model->load($relationFunction);
        }

        return new $this->resourceClass($model);
    }

    public function storeLogic(FormRequest $request, Model $model): JsonResponse
    {
        return $this->saveModel($request->validated(), $model)
            ->response($request)
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function updateLogic(FormRequest $request, Model $model): JsonResource
    {
        $model->fill($request->validated())->save();

        foreach ($this->relationFunctions as $relationFunction) {
            $model->load($relationFunction);
        }

        return new $this->resourceClass($model);
    }

    public function destroyLogic(Model $model): JsonResponse
    {
        $model->delete();

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }

    private function saveModel(array $data, Model $model): JsonResource
    {
        $item = $model::create($data);

        foreach ($this->relationFunctions as $relationFunction) {
            $item->load($relationFunction);
        }

        return new $this->resourceClass($item);
    }

    private function buildFilterSortQuery(array $filters, Model $model): Builder
    {
        $query = $model::query();

        if (is_array($filters['sort'] ?? null)) {
            if ($model->isSortable()) {
                $allowedSorts = $model->getAllowedSorts();

                foreach ($filters['sort'] as $column => $value) {
                    if (! in_array($column, $allowedSorts, true)) {
                        throw new HttpResponseException(
                            Response::json([
                                'message' => 'The given data was invalid.',
                                'errors' => [
                                    "sort.$column" => [
                                        "Sorting by $column is not allowed.",
                                    ],
                                ],
                            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
                        );
                    }
                }

                $query = $query->sort($filters['sort'] ?? []);
            } else {
                throw new HttpResponseException(
                    Response::json([
                        'message' => 'The given data was invalid.',
                        'errors' => [
                            'sort' => [
                                'Sorting on this object is not allowed.',
                            ],
                        ],
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
                );
            }
        }

        if (is_array($filters['filter'] ?? null)) {
            if ($model->isFilterable()) {
                $allowedFilters = $model->getAllowedFilters();

                foreach ($filters['filter'] as $column => $value) {
                    if (! in_array($column, $allowedFilters, true)) {
                        throw new HttpResponseException(
                            Response::json([
                                'message' => 'The given data was invalid.',
                                'errors' => [
                                    "filter.$column" => [
                                        "Filtering by $column is not allowed.",
                                    ],
                                ],
                            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
                        );
                    }
                }

                $query = $query->filter($filters['filter'] ?? []);
            } else {
                throw new HttpResponseException(
                    Response::json([
                        'message' => 'The given data was invalid.',
                        'errors' => [
                            'filter' => [
                                'Filtering on this object is not allowed.',
                            ],
                        ],
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
                );
            }
        }

        $query->with($this->relationFunctions);

        return $query;
    }
}
