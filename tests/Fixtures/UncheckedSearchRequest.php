<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

/**
 * Lets any sort and filter input through, so the tests reach the checks of ConsistentController itself.
 */
class UncheckedSearchRequest extends BaseSearchRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['sort' => ['array'], 'filter' => ['array']];
    }
}
