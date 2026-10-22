<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rejects write requests whose body is not JSON.
 *
 * POST, PUT and PATCH requests with a body but without a JSON Content-Type get 415 Unsupported Media Type
 * with an error message; requests without a body and other methods pass. Registered as the
 * consistent.ensure-json alias.
 *
 * @package Adonyarik\ConsistentApi\Middleware
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class EnsureJsonMiddleware
{
    /**
     * Answers 415 for a POST, PUT or PATCH request with a non-JSON body, otherwise passes it on.
     *
     * The body counts as JSON when Content-Type contains /json or +json (Request::isJson()). A request
     * without a body, such as an action endpoint POST /posts/1/publish, has nothing to check and passes.
     *
     * @param  Request  $request  Incoming request
     * @param  Closure  $next  Next middleware
     *
     * @return \Symfony\Component\HttpFoundation\Response The 415 JSON error, or the response of the next middleware
     */
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
            && $this->hasBody($request)
            && ! $request->isJson()) {
            return response()->json(['message' => 'The request must have Content-Type: application/json header.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return $next($request);
    }

    /**
     * Tells whether the request carries a body.
     *
     * A raw body, form fields or files (PHP keeps no raw body for multipart requests), or a Content-Length
     * above zero.
     *
     * @param  Request  $request  Incoming request
     *
     * @return bool True when there is a body to check
     */
    private function hasBody(Request $request): bool
    {
        return $request->getContent() !== ''
            || $request->request->count() > 0
            || $request->files->count() > 0
            || (int) $request->headers->get('Content-Length', '0') > 0;
    }
}
