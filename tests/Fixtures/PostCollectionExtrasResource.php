<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Its collections carry top-level data of their own.
 */
class PostCollectionExtrasResource extends PostResource
{
    /**
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        return parent::collection($resource)->additional(['version' => 'v1']);
    }
}
