<?php

declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Test middleware that stops the request without calling $next.
 */
class BlockMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return new Response('blocked', 403);
    }
}
