<?php

declare(strict_types=1);

namespace Kanin\Routing;

use Kanin\Container;

/**
 * One route: "when a request with one of these HTTP methods hits this URI,
 * run this action".
 *
 * A URI may contain parameters:
 *   /users/{id}       {id} matches one path segment (anything except "/")
 *   /posts/{slug?}    a trailing "?" makes the parameter optional
 *
 * Route also works as a static "facade": Route::get('/users', ...) is a
 * shortcut for app(Router::class)->get('/users', ...).
 */
class Route
{
    /** The regex a parameter uses when where() did not give it one. */
    public const DEFAULT_PATTERN = '[^/]+';

    /** @var list<string> Uppercase HTTP methods, e.g. ['GET']. */
    private array $methods;

    private string $uri;

    private \Closure|array|string $action;

    private ?string $name = null;

    /** @var list<string> Middleware aliases or class names, in run order. */
    private array $middleware = [];

    /** @var array<string, string> Parameter name => regex. */
    private array $wheres = [];

    /**
     * Create a route. The URI is normalized: "users/" becomes "/users" and
     * "" becomes "/". Methods are stored in uppercase.
     */
    public function __construct(array $methods, string $uri, \Closure|array|string $action)
    {
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $this->uri = '/' . trim($uri, '/');
        $this->action = $action;
    }

    /** The HTTP methods this route answers to, e.g. ['PUT', 'PATCH']. */
    public function methods(): array
    {
        return $this->methods;
    }

    /** The normalized URI pattern, e.g. "/users/{id}". */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * What runs when the route matches: a closure, [Controller::class, 'method']
     * or 'Controller@method'.
     */
    public function getAction(): \Closure|array|string
    {
        return $this->action;
    }

    /** Give the route a name so you can build its URL later with route('name'). */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** The route's name, or null when it has none. */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Add middleware (an alias like 'auth.token' or a class name). Calling it
     * again adds more; it never removes what is already there.
     */
    public function middleware(string|array $middleware): static
    {
        foreach ((array) $middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    /** The route's middleware in the order it will run. */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Restrict what a parameter may look like, using a regex:
     *   ->where('id', '[0-9]+')  or  ->where(['id' => '[0-9]+', 'slug' => '[a-z-]+'])
     */
    public function where(string|array $name, ?string $pattern = null): static
    {
        $patterns = is_array($name) ? $name : [$name => $pattern];

        foreach ($patterns as $parameter => $regex) {
            if (!is_string($regex) || $regex === '') {
                throw new \InvalidArgumentException("A pattern is required for route parameter [{$parameter}].");
            }

            $this->wheres[$parameter] = $regex;
        }

        return $this;
    }

    /**
     * Check a request path against this route. Returns the parameters it found
     * (e.g. ['id' => '5']), [] for a match without parameters, or null when
     * the path does not match. A missing optional parameter is left out.
     */
    public function matches(string $path): ?array
    {
        $path = rtrim($path, '/');

        if (preg_match($this->compile(), $path, $found, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($found as $key => $value) {
            if (is_string($key) && $value !== null) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Turn the URI pattern into a regex, e.g. "/users/{id}" into
     * "#^/users/(?P<id>[^/]+)$#". The root "/" compiles to an empty string,
     * which is why matches() trims the trailing "/" from the path too.
     */
    private function compile(): string
    {
        // Split "/users/{id}" into ["/users", "/{id}"]: plain text and placeholders.
        $pieces = preg_split(
            '#(/?\{[A-Za-z_][A-Za-z0-9_]*\??\})#',
            rtrim($this->uri, '/'),
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        $regex = '';
        foreach ($pieces as $piece) {
            if (!str_contains($piece, '{')) {
                $regex .= preg_quote($piece, '#');
                continue;
            }

            $slash = str_starts_with($piece, '/') ? '/' : '';
            $parameter = trim($piece, '/{?}');
            $pattern = str_replace('#', '\#', $this->wheres[$parameter] ?? self::DEFAULT_PATTERN);
            $group = $slash . '(?P<' . $parameter . '>' . $pattern . ')';

            // Optional: the whole "/value" part may be missing.
            $regex .= str_ends_with($piece, '?}') ? '(?:' . $group . ')?' : $group;
        }

        return '#^' . $regex . '$#';
    }

    /** Facade: register a GET route on the application's router. */
    public static function get(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->get($uri, $action);
    }

    /** Facade: register a POST route on the application's router. */
    public static function post(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->post($uri, $action);
    }

    /** Facade: register a PUT route on the application's router. */
    public static function put(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->put($uri, $action);
    }

    /** Facade: register a PATCH route on the application's router. */
    public static function patch(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->patch($uri, $action);
    }

    /** Facade: register a DELETE route on the application's router. */
    public static function delete(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->delete($uri, $action);
    }

    /** Facade: register a route for several methods, e.g. ['PUT', 'PATCH']. */
    public static function match(array $methods, string $uri, \Closure|array|string $action): Route
    {
        return self::router()->match($methods, $uri, $action);
    }

    /** Facade: register a route for GET, POST, PUT, PATCH and DELETE. */
    public static function any(string $uri, \Closure|array|string $action): Route
    {
        return self::router()->any($uri, $action);
    }

    /** Facade: share a prefix and/or middleware between several routes. */
    public static function group(array $attributes, \Closure $routes): void
    {
        self::router()->group($attributes, $routes);
    }

    /** Facade: register the five REST routes for a controller. */
    public static function apiResource(string $name, string $controller, array $options = []): void
    {
        self::router()->apiResource($name, $controller, $options);
    }

    /** The router the facade talks to (the one stored in the container). */
    private static function router(): Router
    {
        return Container::getInstance()->make(Router::class);
    }
}
