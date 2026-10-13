<?php

declare(strict_types=1);

namespace Kanin\Middleware;

use Closure;
use Kanin\Http\HttpException;
use Kanin\Http\Method;
use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Support\Config;

/**
 * Adds CORS (Cross-Origin Resource Sharing) headers so that JavaScript
 * running on another domain is allowed to call your API from a browser.
 *
 * Every setting lives in config/cors.php. Requests that carry no "Origin"
 * header (curl, Postman, server-to-server calls) are passed through untouched,
 * because CORS is a browser-only concept.
 */
class HandleCors implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param Config $config The application configuration; the "cors.*" keys are read.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Answer a browser "preflight" request directly, or add CORS headers to
     * the normal response produced by the rest of the application.
     *
     * @param Request $request The incoming request.
     * @param Closure $next    Calls the next middleware (and finally your route).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if ($origin === null || $origin === '') {
            return $next($request);
        }

        $allowed = $this->isAllowedOrigin($origin);

        if ($this->isPreflight($request)) {
            // A preflight never reaches the router: we answer it right here.
            $response = new Response('', 204);

            return $allowed ? $this->addPreflightHeaders($request, $response, $origin) : $response;
        }

        $response = $next($request);

        return $allowed ? $this->addActualRequestHeaders($response, $origin) : $response;
    }

    /**
     * A preflight is an OPTIONS request that asks "may I send method X?".
     */
    private function isPreflight(Request $request): bool
    {
        if ($request->header('Access-Control-Request-Method') === null) {
            return false;
        }

        try {
            return $request->method() === Method::OPTIONS;
        } catch (HttpException) {
            // An unknown HTTP verb is not a preflight; the router will answer 405.
            return false;
        }
    }

    /**
     * The origin is allowed when the list contains "*" or the exact origin.
     *
     * An origin with control characters (CR, LF, NUL...) is never allowed: it
     * could be echoed back into a response header.
     */
    private function isAllowedOrigin(string $origin): bool
    {
        if ($this->hasControlCharacters($origin)) {
            return false;
        }

        $origins = $this->list('allowed_origins');

        return in_array('*', $origins, true) || in_array($origin, $origins, true);
    }

    /**
     * Headers sent back on a successful preflight.
     */
    private function addPreflightHeaders(Request $request, Response $response, string $origin): Response
    {
        $this->addOriginHeaders($response, $origin);
        $this->headerIfNotEmpty($response, 'Access-Control-Allow-Methods', implode(', ', $this->list('allowed_methods')));
        $this->headerIfNotEmpty($response, 'Access-Control-Allow-Headers', $this->allowedHeadersFor($request));
        $response->header('Access-Control-Max-Age', (string) $this->maxAge());

        return $response;
    }

    /**
     * Headers added to a normal (non-preflight) response.
     */
    private function addActualRequestHeaders(Response $response, string $origin): Response
    {
        $this->addOriginHeaders($response, $origin);
        $this->headerIfNotEmpty($response, 'Access-Control-Expose-Headers', implode(', ', $this->list('exposed_headers')));

        return $response;
    }

    /**
     * Allow-Origin (plus Vary and Allow-Credentials when needed).
     */
    private function addOriginHeaders(Response $response, string $origin): void
    {
        if (in_array('*', $this->list('allowed_origins'), true) && ! $this->supportsCredentials()) {
            $response->header('Access-Control-Allow-Origin', '*');
        } else {
            // Echoing the origin means the answer differs per origin, so caches must know.
            $response->header('Access-Control-Allow-Origin', $origin);
            $this->addVaryOrigin($response);
        }

        if ($this->supportsCredentials()) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * The configured header list, or the browser's own request when the list is ['*'].
     */
    private function allowedHeadersFor(Request $request): string
    {
        $headers = $this->list('allowed_headers');

        if ($headers === ['*']) {
            $requested = trim((string) $request->header('Access-Control-Request-Headers', ''));

            // Never echo a value that could break out of the header line.
            return $this->hasControlCharacters($requested) ? '' : $requested;
        }

        return implode(', ', $headers);
    }

    /**
     * Add "Origin" to the Vary header without losing values already there.
     */
    private function addVaryOrigin(Response $response): void
    {
        $vary = trim((string) $response->getHeader('Vary'));

        if ($vary === '') {
            $response->header('Vary', 'Origin');

            return;
        }

        $parts = array_map(static fn (string $part): string => strtolower(trim($part)), explode(',', $vary));

        if (! in_array('origin', $parts, true)) {
            $response->header('Vary', $vary . ', Origin');
        }
    }

    private function headerIfNotEmpty(Response $response, string $name, string $value): void
    {
        if ($value !== '') {
            $response->header($name, $value);
        }
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /**
     * Read a "cors.*" list, keeping only string entries (missing → empty list).
     *
     * @return list<string>
     */
    private function list(string $key): array
    {
        $value = $this->config->get('cors.' . $key, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function maxAge(): int
    {
        return (int) $this->config->get('cors.max_age', 0);
    }

    private function supportsCredentials(): bool
    {
        return $this->config->get('cors.supports_credentials', false) === true;
    }
}
