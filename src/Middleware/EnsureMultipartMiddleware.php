<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnsureMultipartMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $contentType = (string) $request->header('Content-Type', '');

        if ($request->isMethod('POST') && stripos($contentType, 'multipart/form-data') === 0) {
            return $next($request);
        }

        return response()->json(
            ['error' => 'The request must have Content-Type: multipart/form-data header.'],
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE
        );
    }
}
