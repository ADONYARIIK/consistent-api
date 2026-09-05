<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as IlluminateController;

class Controller extends IlluminateController
{
    use AuthorizesRequests, ValidatesRequests;
}
