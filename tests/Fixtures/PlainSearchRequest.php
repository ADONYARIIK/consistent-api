<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Adonyarik\ConsistentApi\Requests\BaseSearchRequest;

/**
 * A search request without $model: only the base rules apply.
 */
class PlainSearchRequest extends BaseSearchRequest {}
