<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Fixtures;

use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Test middleware: adds "X-Fixture: seen" to whatever response comes back.
 */
final class AddHeaderMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request)->header('X-Fixture', 'seen');
    }
}
