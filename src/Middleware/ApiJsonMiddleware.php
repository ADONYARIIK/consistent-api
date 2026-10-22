<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes API requests ask for JSON.
 *
 * Sets Accept: application/json on requests under config('consistentapi.api_url_prefix') that do not ask
 * for a specific type, so that Laravel renders validation errors, authentication failures and exceptions
 * as JSON instead of redirects or HTML pages. ConsistentApiProvider prepends it to the global middleware
 * unless consistentapi.api_json_enabled is off; it is also registered as the consistent.api-json alias.
 *
 * @package Adonyarik\ConsistentApi\Middleware
 * @author Yaroslav Tyrchenko <tyrchenkoy@gmail.com>
 * @since 1.0.0
 */
class ApiJsonMiddleware
{
    /**
     * Media types that do not count as a specific choice of the client.
     *
     * A missing Accept header, any type, and the HTML types browsers send first.
     */
    private const GENERIC_TYPES = ['', '*', '*/*', 'text/html', 'application/xhtml+xml'];

    /**
     * Sets the Accept header for API URLs, then passes the request on.
     *
     * Only a request without a preference of its own is changed: no Accept header, any type (*\/*) or
     * HTML first, as browsers send it. A client that already asks for JSON (application/json,
     * application/vnd.api+json) or for another specific type (text/csv for an export, application/pdf)
     * keeps its header, so content negotiation and $request->accepts() keep working.
     *
     * @param  Request  $request  Incoming request
     * @param  Closure  $next  Next middleware
     *
     * @return Response Response of the next middleware
     *
     * @see ApiJsonMiddleware::isApiRequest()
     */
    public function handle(Request $request, Closure $next)
    {
        if (static::isApiRequest($request) && ! $request->wantsJson() && $this->prefersGenericType($request)) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }

    /**
     * Tells whether the request goes to the API prefix.
     *
     * Matches /{prefix} and everything under it. Slashes around the prefix are ignored, as Laravel ignores
     * them for route prefixes, so 'api', '/api/' and 'api/' behave the same; a nested prefix such as
     * 'api/v1' works too. An empty prefix puts the API at the root, so every request is an API request.
     *
     * @param  Request  $request  Incoming request
     *
     * @return bool True for a URL under config('consistentapi.api_url_prefix')
     */
    public static function isApiRequest(Request $request): bool
    {
        $prefix = trim((string) config('consistentapi.api_url_prefix', ''), '/');

        return $prefix === '' || $request->is($prefix, $prefix.'/*');
    }

    /**
     * Tells whether the client's preferred media type is not a specific one.
     *
     * Looks at the type the client prefers most (the first acceptable content type).
     *
     * @param  Request  $request  Incoming request
     *
     * @return bool True without an Accept header, for *\/* and for HTML
     */
    private function prefersGenericType(Request $request): bool
    {
        $preferred = $request->getAcceptableContentTypes()[0] ?? '';

        return in_array(strtolower($preferred), self::GENERIC_TYPES, true);
    }
}
