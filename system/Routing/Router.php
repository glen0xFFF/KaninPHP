<?php

declare(strict_types=1);

namespace Kanin\Routing;

use Kanin\Container;
use Kanin\Http\JsonResponse;
use Kanin\Http\Method;
use Kanin\Http\MethodNotAllowedHttpException;
use Kanin\Http\NotFoundHttpException;
use Kanin\Http\Request;
use Kanin\Http\Response;

/**
 * Keeps the list of routes and turns a Request into a Response:
 * find the matching route, run its middleware, call its action and
 * convert whatever the action returned into a Response.
 */
class Router
{
    /** The actions apiResource() knows about, in registration order. */
    private const RESOURCE_ACTIONS = ['index', 'store', 'show', 'update', 'destroy'];

    /** @var list<Route> */
    private array $routes = [];

    /** @var list<array{prefix: string, middleware: list<string>}> Open group() calls. */
    private array $groupStack = [];

    /** @var array<string, string> Alias => middleware class name. */
    private array $middlewareAliases = [];

    /** The container builds controllers and middleware and calls actions. */
    public function __construct(private Container $container)
    {
    }

    /** Register a route that answers GET requests (and HEAD requests). */
    public function get(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['GET'], $uri, $action);
    }

    /** Register a route that answers POST requests. */
    public function post(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['POST'], $uri, $action);
    }

    /** Register a route that answers PUT requests. */
    public function put(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['PUT'], $uri, $action);
    }

    /** Register a route that answers PATCH requests. */
    public function patch(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['PATCH'], $uri, $action);
    }

    /** Register a route that answers DELETE requests. */
    public function delete(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['DELETE'], $uri, $action);
    }

    /**
     * Register a route for several methods at once, e.g. ['PUT', 'PATCH'].
     * The prefix and middleware of any surrounding group() are applied.
     */
    public function match(array $methods, string $uri, \Closure|array|string $action): Route
    {
        $group = $this->currentGroup();

        $route = new Route($methods, $group['prefix'] . '/' . trim($uri, '/'), $action);
        $route->middleware($group['middleware']);

        $this->routes[] = $route;

        return $route;
    }

    /** Register a route for GET, POST, PUT, PATCH and DELETE. */
    public function any(string $uri, \Closure|array|string $action): Route
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $action);
    }

    /**
     * Share attributes between routes. Supported keys:
     *   'prefix'     => 'admin'               (URI prefix, nests: "api/admin")
     *   'middleware' => 'auth.token' or [...]  (runs before the routes' own)
     * The closure receives this router.
     */
    public function group(array $attributes, \Closure $routes): void
    {
        $parent = $this->currentGroup();
        $prefix = trim((string) ($attributes['prefix'] ?? ''), '/');

        $this->groupStack[] = [
            'prefix' => trim($parent['prefix'] . '/' . $prefix, '/'),
            'middleware' => [...$parent['middleware'], ...(array) ($attributes['middleware'] ?? [])],
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Register the five REST routes of a controller:
     *   GET /{name} index, POST /{name} store, GET /{name}/{id} show,
     *   PUT|PATCH /{name}/{id} update, DELETE /{name}/{id} destroy.
     * Routes are named "{name}.index" etc. and {id} must be digits.
     * Options: 'only' => ['index', 'show'], 'middleware' => 'auth.token'.
     */
    public function apiResource(string $name, string $controller, array $options = []): void
    {
        $only = $options['only'] ?? self::RESOURCE_ACTIONS;
        $unknown = array_diff($only, self::RESOURCE_ACTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown resource action(s) [' . implode(', ', $unknown) . '].');
        }

        $base = '/' . trim($name, '/');
        $definitions = [
            'index' => [['GET'], $base],
            'store' => [['POST'], $base],
            'show' => [['GET'], $base . '/{id}'],
            'update' => [['PUT', 'PATCH'], $base . '/{id}'],
            'destroy' => [['DELETE'], $base . '/{id}'],
        ];

        foreach ($definitions as $action => [$methods, $uri]) {
            if (!in_array($action, $only, true)) {
                continue;
            }

            $this->match($methods, $uri, [$controller, $action])
                ->name(str_replace('/', '.', trim($name, '/')) . '.' . $action)
                ->where('id', '[0-9]+')
                ->middleware($options['middleware'] ?? []);
        }
    }

    /** Give a middleware class a short name, e.g. 'auth.token'. */
    public function aliasMiddleware(string $alias, string $class): void
    {
        $this->middlewareAliases[$alias] = $class;
    }

    /** Every registered route, in registration order. */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Find the route for a request. HEAD requests use GET routes.
     * Throws a 405 when the path exists for other methods, else a 404.
     */
    public function findRoute(Request $request): Route
    {
        $method = $request->method();
        $path = $request->path();
        $allowed = [];

        foreach ($this->routes as $route) {
            if ($route->matches($path) === null) {
                continue;
            }

            $methods = $route->methods();
            if (in_array($method->value, $methods, true) || ($method === Method::HEAD && in_array('GET', $methods, true))) {
                return $route;
            }

            array_push($allowed, ...$methods);
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new NotFoundHttpException();
    }

    /**
     * Handle a request: find its route, store the route parameters on the
     * request, run the route middleware, then call the action.
     */
    public function dispatch(Request $request): Response
    {
        $route = $this->findRoute($request);
        $parameters = $route->matches($request->path()) ?? [];

        $request->setRouteParameters($parameters);
        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->send($request)
            ->through($this->resolveMiddleware($route->getMiddleware()))
            ->then(function (Request $request) use ($route, $parameters): Response {
                $this->container->instance(Request::class, $request);
                $action = $this->resolveAction($route->getAction());

                return static::toResponse($this->container->call($action, $parameters));
            });
    }

    /**
     * Build the path of a named route, e.g. url('users.show', ['id' => 5])
     * gives "/users/5". Extra parameters become the query string.
     */
    public function url(string $name, array $parameters = []): string
    {
        return UrlGenerator::path($this->findByName($name), $parameters);
    }

    /**
     * Convert what an action returned into a Response:
     * Response as-is, array/JsonSerializable as JSON, string/Stringable as
     * text, null as an empty 204 response.
     */
    public static function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_array($result), $result instanceof \JsonSerializable => new JsonResponse($result),
            is_string($result), $result instanceof \Stringable => new Response((string) $result),
            $result === null => new Response('', 204),
            default => throw new \UnexpectedValueException(
                'A route action returned an unsupported value of type [' . get_debug_type($result) . '].',
            ),
        };
    }

    /** The innermost open group, or an empty one outside any group. */
    private function currentGroup(): array
    {
        return $this->groupStack === []
            ? ['prefix' => '', 'middleware' => []]
            : $this->groupStack[array_key_last($this->groupStack)];
    }

    /** The first route registered with this name. */
    private function findByName(string $name): Route
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        throw new \InvalidArgumentException("Route [{$name}] is not defined.");
    }

    /** Replace aliases such as 'auth.token' with their class names. */
    private function resolveMiddleware(array $middleware): array
    {
        return array_map(function (string $name): string {
            if (isset($this->middlewareAliases[$name])) {
                return $this->middlewareAliases[$name];
            }

            if (!class_exists($name)) {
                throw new \InvalidArgumentException("Middleware [{$name}] is not an alias or a class.");
            }

            return $name;
        }, $middleware);
    }

    /**
     * Turn 'Controller@method' or [Controller::class, 'method'] into a
     * callable the container can call. Only public methods are allowed.
     */
    private function resolveAction(\Closure|array|string $action): \Closure|array
    {
        if ($action instanceof \Closure) {
            return $action;
        }

        if (is_string($action)) {
            if (substr_count($action, '@') !== 1) {
                throw new \InvalidArgumentException("Route action [{$action}] must look like [Controller@method].");
            }

            $action = explode('@', $action);
        }

        if (!array_is_list($action) || count($action) !== 2 || !is_string($action[0]) || !is_string($action[1])) {
            throw new \InvalidArgumentException('Route action arrays must look like [Controller::class, \'method\'].');
        }

        [$class, $method] = $action;
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Controller [{$class}] does not exist.");
        }

        if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
            throw new \InvalidArgumentException("Method [{$class}::{$method}] does not exist or is not public.");
        }

        return [$class, $method];
    }
}
