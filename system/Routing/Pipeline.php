<?php

declare(strict_types=1);

namespace Kanin\Routing;

use Kanin\Container;
use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Sends a request through a list of middleware, like layers of an onion:
 *
 *   (new Pipeline($container))
 *       ->send($request)
 *       ->through([First::class, Second::class])
 *       ->then(fn (Request $request) => new Response('Hello'));
 *
 * First runs outermost: First (before) -> Second (before) -> destination
 * -> Second (after) -> First (after). A middleware can also stop early by
 * returning a Response without calling $next.
 */
class Pipeline
{
    private ?Request $request = null;

    /** @var list<string|object> Class names or middleware objects. */
    private array $middleware = [];

    /**
     * The container is used to build middleware given as class names.
     */
    public function __construct(private Container $container)
    {
    }

    /**
     * Set the request that will travel through the middleware.
     */
    public function send(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Set the middleware: class names (built by the container) or objects.
     * The first one in the list runs first (outermost).
     */
    public function through(array $middleware): static
    {
        $this->middleware = array_values($middleware);

        return $this;
    }

    /**
     * Run the request through every middleware and finally into
     * $destination, returning the Response that comes back out.
     */
    public function then(\Closure $destination): Response
    {
        if ($this->request === null) {
            throw new \LogicException('Call send() with a request before then().');
        }

        // Wrap from the inside out, so the first middleware ends up outermost.
        $next = $destination;
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = function (Request $request) use ($middleware, $next): Response {
                return $this->resolve($middleware)->handle($request, $next);
            };
        }

        return $next($this->request);
    }

    /**
     * Turn a class name into an object and check it really is middleware.
     */
    private function resolve(string|object $middleware): Middleware
    {
        $instance = is_string($middleware) ? $this->container->make($middleware) : $middleware;

        if (!$instance instanceof Middleware) {
            $name = is_string($middleware) ? $middleware : $middleware::class;

            throw new \InvalidArgumentException("Middleware [{$name}] must implement " . Middleware::class . '.');
        }

        return $instance;
    }
}
