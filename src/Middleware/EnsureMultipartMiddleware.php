<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lets through only POST requests with a multipart/form-data body.
 *
 * Meant for upload routes: any other request, including other methods, gets 415 Unsupported Media Type
 * with an error message. A POST that overrides its method with _method=PATCH counts as a POST, since PHP
 * fills $_FILES only for real POST requests. Registered as the consistent.ensure-multipart alias.
 *
 * @package Adonyarik\ConsistentApi\Middleware
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class EnsureMultipartMiddleware
{
    /**
     * Passes a multipart POST request on, answers 415 for anything else.
     *
     * The method is the one the client really sent, so POST with _method=PUT / PATCH (Laravel's way to
     * upload files on update) passes. Content-Type must start with multipart/form-data (case-insensitive);
     * the boundary parameter after it is not checked.
     *
     * @param  Request  $request  Incoming request
     * @param  Closure  $next  Next middleware
     *
     * @return \Symfony\Component\HttpFoundation\Response Response of the next middleware, or the 415 JSON error
     */
    public function handle(Request $request, Closure $next)
    {
        $contentType = (string) $request->header('Content-Type', '');

        if ($request->getRealMethod() === 'POST' && stripos($contentType, 'multipart/form-data') === 0) {
            return $next($request);
        }

        return response()->json(
            ['message' => 'The request must have Content-Type: multipart/form-data header.'],
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE
        );
    }
}
