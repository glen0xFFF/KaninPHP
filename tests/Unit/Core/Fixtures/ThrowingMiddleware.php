<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Fixtures;

use Kanin\Http\HttpException;
use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Test middleware: always fails before the router is reached.
 */
final class ThrowingMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        throw new HttpException(418, 'I am a teapot.');
    }
}
