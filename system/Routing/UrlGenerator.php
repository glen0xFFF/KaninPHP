<?php

declare(strict_types=1);

namespace Kanin\Routing;

/**
 * Builds the path of a route from its URI pattern. Used by Router::url()
 * and the route() helper:
 *
 *   "/users/{id}"     + ['id' => 5]               -> "/users/5"
 *   "/posts/{slug?}"  + []                         -> "/posts"
 *   "/users/{id}"     + ['id' => 5, 'page' => 2]   -> "/users/5?page=2"
 */
final class UrlGenerator
{
    /**
     * Fill in the route's {parameters}. Values are URL-encoded; parameters
     * the URI does not use are added as a query string.
     */
    public static function path(Route $route, array $parameters = []): string
    {
        $name = $route->getName() ?? $route->uri();

        $path = preg_replace_callback(
            '#(/?)\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}#',
            function (array $match) use (&$parameters, $name): string {
                [, $slash, $key] = $match;
                $optional = ($match[3] ?? '') === '?';

                if (!isset($parameters[$key])) {
                    if ($optional) {
                        return '';
                    }

                    throw new \InvalidArgumentException("Missing required parameter [{$key}] for route [{$name}].");
                }

                if (!is_scalar($parameters[$key])) {
                    throw new \InvalidArgumentException("Route parameter [{$key}] must be a string or number.");
                }

                $value = rawurlencode((string) $parameters[$key]);
                unset($parameters[$key]);

                return $slash . $value;
            },
            $route->uri(),
        );

        $path = $path === '' ? '/' : $path;

        return $parameters === [] ? $path : $path . '?' . http_build_query($parameters);
    }
}
