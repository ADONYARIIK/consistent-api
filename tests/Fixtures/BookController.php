<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Controllers\ConsistentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class BookController extends ConsistentController
{
    protected string $resourceClass = BookResource::class;

    protected array $relationFunctions = ['author'];

    public function index(SearchBookRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Book);
    }

    /**
     * The index without the value rules of #[Filterable]: the controller alone checks the filter input.
     */
    public function plainIndex(PlainSearchRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Book);
    }

    /**
     * The index without any sort and filter rules: only the controller checks the input.
     */
    public function uncheckedIndex(UncheckedSearchRequest $request): JsonResponse
    {
        return $this->indexLogic($request, new Book);
    }

    public function show(Book $book): JsonResource
    {
        return $this->selectLogic($book);
    }

    public function store(StoreBookRequest $request): JsonResponse
    {
        return $this->storeLogic($request, new Book);
    }

    /**
     * Sets the author on the server; the client cannot choose it.
     */
    public function storeForAnn(StoreBookRequest $request): JsonResponse
    {
        $book = new Book(['price' => 77]);
        $book->author()->associate(Author::where('name', 'Ann')->firstOrFail());

        return $this->storeLogic($request, $book);
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResource
    {
        return $this->updateLogic($request, $book);
    }

    public function destroy(Book $book): JsonResponse
    {
        return $this->destroyLogic($book);
    }
}
