<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Contracts;

/**
 * Marks a model whose index may return every row without pagination.
 *
 * When the listed model implements it and the request has paginate=false (or 0),
 * ConsistentController::indexLogic() returns all filtered and sorted rows under the data container,
 * without the meta block. Meant for short lists such as dictionaries; the interface has no methods.
 *
 * @package Adonyarik\ConsistentApi\Contracts
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
interface WithoutPaginationModelContract {}
