<?php

declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Test middleware that records when it runs, so tests can check the order.
 */
class TraceMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private string $name = 'trace')
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        self::$log[] = $this->name . ':before';
        $response = $next($request);
        self::$log[] = $this->name . ':after';

        return $response;
    }
}
