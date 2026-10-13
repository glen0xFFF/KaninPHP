<?php

declare(strict_types=1);

namespace Kanin\Middleware;

use Closure;
use Kanin\Http\HttpException;
use Kanin\Http\Middleware;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Support\Config;

/**
 * Protects routes with a simple API token.
 *
 * Clients send "Authorization: Bearer <token>". The token must match one of
 * the tokens in config/auth.php (filled from API_TOKENS in .env). The check
 * fails closed: no configured tokens means nobody gets in.
 *
 * Use it on a route with ->middleware('auth.token').
 */
class AuthenticateToken implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param Config $config The application configuration; "auth.tokens" is read.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Let the request through only when it carries a valid bearer token.
     *
     * @param Request $request The incoming request.
     * @param Closure $next    Calls the next middleware (and finally your route).
     *
     * @throws HttpException 401 "Unauthenticated." with a "WWW-Authenticate: Bearer" header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || ! $this->matchesAnyToken($token)) {
            throw new HttpException(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }

    /**
     * Compare against every configured token with hash_equals().
     *
     * Both sides are hashed with sha256 first, so every comparison is between
     * strings of the same length: neither the token length nor how many
     * characters matched changes the response time. We also never stop at the
     * first match.
     */
    private function matchesAnyToken(string $given): bool
    {
        $matched = false;
        $givenHash = hash('sha256', $given);

        foreach ($this->tokens() as $known) {
            // hash_equals() runs first so it is evaluated for every token.
            $matched = hash_equals(hash('sha256', $known), $givenHash) || $matched;
        }

        return $matched;
    }

    /**
     * The configured tokens: non-empty strings only (anything else is ignored).
     *
     * @return list<string>
     */
    private function tokens(): array
    {
        $tokens = $this->config->get('auth.tokens', []);

        if (! is_array($tokens)) {
            return [];
        }

        return array_values(array_filter(
            $tokens,
            static fn (mixed $token): bool => is_string($token) && $token !== '',
        ));
    }
}
