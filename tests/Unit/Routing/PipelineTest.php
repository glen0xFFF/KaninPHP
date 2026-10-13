<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Kanin\Container;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Routing\Pipeline;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Routing\Fixtures\BlockMiddleware;
use Tests\Unit\Routing\Fixtures\TraceMiddleware;

class PipelineTest extends TestCase
{
    protected function setUp(): void
    {
        TraceMiddleware::$log = [];
    }

    public function test_without_middleware_the_destination_receives_the_request(): void
    {
        $request = Request::create('/hello');

        $response = (new Pipeline(new Container()))
            ->send($request)
            ->through([])
            ->then(fn (Request $received) => new Response($received === $request ? 'same' : 'other'));

        $this->assertSame('same', $response->getContent());
    }

    public function test_first_middleware_runs_outermost(): void
    {
        $response = (new Pipeline(new Container()))
            ->send(Request::create('/'))
            ->through([new TraceMiddleware('first'), new TraceMiddleware('second')])
            ->then(function (): Response {
                TraceMiddleware::$log[] = 'destination';

                return new Response('done');
            });

        $this->assertSame('done', $response->getContent());
        $this->assertSame(
            ['first:before', 'second:before', 'destination', 'second:after', 'first:after'],
            TraceMiddleware::$log,
        );
    }

    public function test_class_names_are_built_by_the_container(): void
    {
        (new Pipeline(new Container()))
            ->send(Request::create('/'))
            ->through([TraceMiddleware::class])
            ->then(fn () => new Response());

        $this->assertSame(['trace:before', 'trace:after'], TraceMiddleware::$log);
    }

    public function test_middleware_can_stop_the_request(): void
    {
        $reached = false;

        $response = (new Pipeline(new Container()))
            ->send(Request::create('/'))
            ->through([BlockMiddleware::class, new TraceMiddleware('inner')])
            ->then(function () use (&$reached): Response {
                $reached = true;

                return new Response('ok');
            });

        $this->assertFalse($reached);
        $this->assertSame([], TraceMiddleware::$log);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getContent());
    }

    public function test_objects_that_are_not_middleware_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware [stdClass] must implement Kanin\Http\Middleware.');

        (new Pipeline(new Container()))
            ->send(Request::create('/'))
            ->through([new \stdClass()])
            ->then(fn () => new Response());
    }

    public function test_class_names_that_are_not_middleware_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware [ArrayObject] must implement Kanin\Http\Middleware.');

        (new Pipeline(new Container()))
            ->send(Request::create('/'))
            ->through([\ArrayObject::class])
            ->then(fn () => new Response());
    }

    public function test_then_without_send_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call send() with a request before then().');

        (new Pipeline(new Container()))->through([])->then(fn () => new Response());
    }
}
