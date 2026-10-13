<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * A middleware wraps the handling of a request, like a layer of an onion.
 *
 * It can inspect the request, stop early by returning its own Response,
 * or pass the request on by calling $next($request) and then adjust the
 * Response that comes back.
 */
interface Middleware
{
    /**
     * Handle the request, calling $next($request) to continue down the chain.
     */
    public function handle(Request $request, \Closure $next): Response;
}
