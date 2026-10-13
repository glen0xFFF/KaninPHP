<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    public function test_middleware_interface_can_short_circuit_or_continue(): void
    {
        $middleware = new class implements Middleware {
            public function handle(Request $request, \Closure $next): Response
            {
                if ($request->header('X-Block') !== null) {
                    return new Response('blocked', 403);
                }

                return $next($request)->header('X-Seen', 'yes');
            }
        };
        $next = fn (Request $request): Response => new Response('ok');

        $passed = $middleware->handle(Request::create('/'), $next);
        $blocked = $middleware->handle(Request::create('/', 'GET', [], ['X-Block' => '1']), $next);

        $this->assertSame('ok', $passed->getContent());
        $this->assertSame('yes', $passed->getHeader('X-Seen'));
        $this->assertSame(403, $blocked->getStatusCode());
        $this->assertSame('blocked', $blocked->getContent());
    }
}
