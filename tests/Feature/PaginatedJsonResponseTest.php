<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Responses\PaginatedJsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Fixtures\Post;
use Tests\Fixtures\PostCollectionExtrasResource;
use Tests\Fixtures\PostResource;
use Tests\TestCase;

class PaginatedJsonResponseTest extends TestCase
{
    public function test_wraps_the_page_in_the_configured_containers(): void
    {
        config(['consistentapi.pagination.data_container_name' => 'data', 'consistentapi.pagination.meta_container_name' => 'pagination']);
        $paginator = new LengthAwarePaginator([(new Post)->forceFill(['id' => 3, 'title' => 'C'])], 21, 10, 3, ['path' => 'http://localhost/api/posts']);

        $response = new PaginatedJsonResponse($paginator, PostResource::class);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'data' => [['id' => 3, 'title' => 'C']],
            'pagination' => [
                'current_page' => 3,
                'last_page' => 3,
                'from' => 21,
                'to' => 21,
                'total' => 21,
                'per_page' => 10,
                'path' => 'http://localhost/api/posts',
            ],
        ], $response->getData(true));
    }

    public function test_container_names_can_be_passed_and_an_empty_page_has_no_range(): void
    {
        $paginator = new LengthAwarePaginator([], 0, 15, 1);

        $data = (new PaginatedJsonResponse($paginator, dataContainerName: 'rows', metaContainerName: 'info'))->getData(true);

        $this->assertSame([], $data['rows']);
        $this->assertNull($data['info']['from']);
        $this->assertNull($data['info']['to']);
        $this->assertSame(1, $data['info']['last_page']);
    }

    public function test_takes_any_length_aware_paginator_and_merges_what_the_collection_adds(): void
    {
        $paginator = new class([(new Post)->forceFill(['id' => 1, 'title' => 'A'])], 1, 10) extends LengthAwarePaginator {};

        $data = (new PaginatedJsonResponse($paginator, PostCollectionExtrasResource::class))->getData(true);

        $this->assertSame([['id' => 1, 'title' => 'A']], $data['items']);
        $this->assertSame('v1', $data['version']);
        $this->assertSame(1, $data['meta']['total']);
    }
}
