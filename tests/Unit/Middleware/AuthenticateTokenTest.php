<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Kanin\Http\HttpException;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Middleware\AuthenticateToken;
use Kanin\Support\Config;
use PHPUnit\Framework\TestCase;

final class AuthenticateTokenTest extends TestCase
{
    private int $nextCalls = 0;

    private function middleware(mixed $tokens): AuthenticateToken
    {
        return new AuthenticateToken(new Config(['auth' => ['tokens' => $tokens]]));
    }

    private function next(): \Closure
    {
        return function (Request $request): Response {
            $this->nextCalls++;

            return new Response('secret area', 200);
        };
    }

    private function requestWithAuthorization(?string $authorization): Request
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];

        return Request::create('/api/users', 'POST', [], $headers);
    }

    /**
     * Run the middleware and assert it refused with the exact 401 shape.
     */
    private function assertUnauthenticated(AuthenticateToken $middleware, Request $request): void
    {
        try {
            $middleware->handle($request, $this->next());
            $this->fail('Expected a 401 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Unauthenticated.', $e->getMessage());
            $this->assertSame(['WWW-Authenticate' => 'Bearer'], $e->getHeaders());
        }

        $this->assertSame(0, $this->nextCalls, 'The protected route must not run.');
    }

    public function test_a_prefix_or_extension_of_a_valid_token_is_refused(): void
    {
        $middleware = $this->middleware(['test-token']);

        $this->assertUnauthenticated($middleware, $this->requestWithAuthorization('Bearer test-tok'));
        $this->assertUnauthenticated($middleware, $this->requestWithAuthorization('Bearer test-token-longer'));
    }

    public function test_valid_token_reaches_the_route(): void
    {
        $response = $this->middleware(['test-token'])->handle(
            $this->requestWithAuthorization('Bearer test-token'),
            $this->next(),
        );

        $this->assertSame(1, $this->nextCalls);
        $this->assertSame('secret area', $response->getContent());
    }

    public function test_any_configured_token_is_accepted(): void
    {
        $response = $this->middleware(['first-token', 'second-token'])->handle(
            $this->requestWithAuthorization('Bearer second-token'),
            $this->next(),
        );

        $this->assertSame(1, $this->nextCalls);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_bearer_scheme_is_case_insensitive(): void
    {
        $this->middleware(['test-token'])->handle($this->requestWithAuthorization('bearer test-token'), $this->next());

        $this->assertSame(1, $this->nextCalls);
    }

    public function test_missing_authorization_header_is_rejected(): void
    {
        $this->assertUnauthenticated($this->middleware(['test-token']), $this->requestWithAuthorization(null));
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->assertUnauthenticated($this->middleware(['test-token']), $this->requestWithAuthorization('Bearer wrong-token'));
    }

    public function test_prefix_of_a_valid_token_is_rejected(): void
    {
        $this->assertUnauthenticated($this->middleware(['test-token']), $this->requestWithAuthorization('Bearer test'));
    }

    public function test_non_bearer_scheme_is_rejected(): void
    {
        $this->assertUnauthenticated($this->middleware(['test-token']), $this->requestWithAuthorization('Basic test-token'));
    }

    public function test_empty_bearer_token_is_rejected(): void
    {
        $this->assertUnauthenticated($this->middleware(['test-token']), $this->requestWithAuthorization('Bearer '));
    }

    public function test_empty_token_list_rejects_everyone(): void
    {
        $this->assertUnauthenticated($this->middleware([]), $this->requestWithAuthorization('Bearer test-token'));
    }

    public function test_missing_auth_config_rejects_everyone(): void
    {
        $middleware = new AuthenticateToken(new Config([]));

        $this->assertUnauthenticated($middleware, $this->requestWithAuthorization('Bearer test-token'));
    }

    public function test_non_array_tokens_config_rejects_everyone(): void
    {
        $this->assertUnauthenticated($this->middleware('test-token'), $this->requestWithAuthorization('Bearer test-token'));
    }

    public function test_empty_and_non_string_configured_tokens_are_ignored(): void
    {
        $middleware = $this->middleware(['', 123, null]);

        $this->assertUnauthenticated($middleware, $this->requestWithAuthorization('Bearer 123'));
    }
}
