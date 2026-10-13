<?php

declare(strict_types=1);

/*
 * Global helper functions, loaded automatically by Composer.
 *
 * They are small shortcuts to services in the container, so that
 * `config('app.name')` reads better than
 * `Container::getInstance()->make(Config::class)->get('app.name')`.
 *
 * Each one is wrapped in `function_exists` so an app can define its own
 * version first if it really needs to.
 */

use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Database\QueryBuilder;
use Kanin\Http\HttpException;
use Kanin\Http\JsonResponse;
use Kanin\Http\NotFoundHttpException;
use Kanin\Http\Request;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use Kanin\Support\Env;

if (! function_exists('app')) {
    /**
     * Get the container, or build a service from it: app(Router::class).
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract, $parameters);
    }
}

if (! function_exists('config')) {
    /**
     * Read a config value with dot notation: config('app.name', 'Default').
     * With no key, returns the Config object itself.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app(Config::class);

        return $key === null ? $config : $config->get($key, $default);
    }
}

if (! function_exists('env')) {
    /**
     * Read an environment variable (from .env or the server): env('APP_DEBUG', false).
     * Use it inside config/*.php files, and config() everywhere else.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (! function_exists('response')) {
    /**
     * Build a JSON response: return response($user, 201);
     */
    function response(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }
}

if (! function_exists('route')) {
    /**
     * Build the URL path of a named route: route('users.show', ['id' => 1]) → '/api/users/1'.
     */
    function route(string $name, array $parameters = []): string
    {
        return app(Router::class)->url($name, $parameters);
    }
}

if (! function_exists('url')) {
    /**
     * Build a full URL from APP_URL: url('api/users') → 'http://localhost:8000/api/users'.
     */
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('request')) {
    /**
     * Get the current Request, or one input value from it: request('page', 1).
     */
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = app(Request::class);

        return $key === null ? $request : $request->input($key, $default);
    }
}

if (! function_exists('db')) {
    /**
     * Get the database Connection, or start a query on a table: db('users')->where('id', 1)->first().
     */
    function db(?string $table = null): Connection|QueryBuilder
    {
        $connection = app(Connection::class);

        return $table === null ? $connection : $connection->table($table);
    }
}

if (! function_exists('abort')) {
    /**
     * Stop the request with an HTTP error: abort(403, 'Forbidden.').
     * The exception handler turns it into a JSON error response.
     */
    function abort(int $code, string $message = '', array $headers = []): never
    {
        if ($code === 404 && $headers === []) {
            throw new NotFoundHttpException($message === '' ? 'Not Found' : $message);
        }

        throw new HttpException($code, $message, $headers);
    }
}

if (! function_exists('dd')) {
    /**
     * "Dump and die": print each value with var_dump, then stop the script.
     * Handy while debugging; never leave it in committed code.
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            var_dump($var);
        }

        exit(1);
    }
}
