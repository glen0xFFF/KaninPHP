<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Middleware\HandleCors;
use Kanin\Support\Config;
use PHPUnit\Framework\TestCase;

final class HandleCorsTest extends TestCase
{
    private int $nextCalls = 0;

    /**
     * Build the middleware with the defaults from config/cors.php, overriding some keys.
     */
    private function cors(array $overrides = []): HandleCors
    {
        $defaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
            'exposed_headers' => [],
            'max_age' => 600,
            'supports_credentials' => false,
        ];

        return new HandleCors(new Config(['cors' => array_replace($defaults, $overrides)]));
    }

    /**
     * A "next" closure that records the call and returns the given response.
     */
    private function next(?Response $response = null): \Closure
    {
        $response ??= new Response('ok', 200);

        return function (Request $request) use ($response): Response {
            $this->nextCalls++;

            return $response;
        };
    }

    private function preflight(string $origin, array $extraHeaders = []): Request
    {
        return Request::create('/api/users', 'OPTIONS', [], array_merge([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ], $extraHeaders));
    }

    public function test_request_without_origin_passes_through_untouched(): void
    {
        $original = new Response('ok', 200);

        $response = $this->cors()->handle(Request::create('/api/users'), $this->next($original));

        $this->assertSame($original, $response);
        $this->assertSame(1, $this->nextCalls);
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_empty_origin_header_is_treated_as_missing(): void
    {
        $response = $this->cors()->handle(Request::create('/api/users', 'GET', [], ['Origin' => '']), $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_wildcard_origin_answers_with_star_and_no_vary(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);

        $response = $this->cors()->handle($request, $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertNull($response->getHeader('Vary'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Credentials'));
        $this->assertNull($response->getHeader('Access-Control-Expose-Headers'));
    }

    public function test_listed_origin_is_echoed_with_vary_origin(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);

        $response = $this->cors(['allowed_origins' => ['https://app.example.com']])->handle($request, $this->next());

        $this->assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeader('Vary'));
    }

    public function test_disallowed_origin_on_actual_request_leaves_response_untouched(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://evil.example.com']);

        $response = $this->cors(['allowed_origins' => ['https://app.example.com']])->handle($request, $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertSame('ok', $response->getContent());
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
        $this->assertNull($response->getHeader('Vary'));
    }

    public function test_origin_must_match_exactly(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com.evil.com']);

        $response = $this->cors(['allowed_origins' => ['https://app.example.com']])->handle($request, $this->next());

        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_missing_cors_config_allows_no_origin(): void
    {
        $middleware = new HandleCors(new Config([]));
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);

        $response = $middleware->handle($request, $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_wildcard_with_credentials_echoes_the_origin(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);

        $response = $this->cors(['supports_credentials' => true])->handle($request, $this->next());

        $this->assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeader('Vary'));
        $this->assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
    }

    public function test_exposed_headers_are_added_when_configured(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);

        $response = $this->cors(['exposed_headers' => ['X-Total', 'X-Page']])->handle($request, $this->next());

        $this->assertSame('X-Total, X-Page', $response->getHeader('Access-Control-Expose-Headers'));
    }

    public function test_existing_vary_header_is_extended_not_replaced(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => 'https://app.example.com']);
        $original = new Response('ok', 200, ['Vary' => 'Accept']);

        $response = $this->cors(['allowed_origins' => ['https://app.example.com']])->handle($request, $this->next($original));

        $this->assertSame('Accept, Origin', $response->getHeader('Vary'));
    }

    public function test_preflight_is_answered_with_204_without_calling_next(): void
    {
        $response = $this->cors()->handle($this->preflight('https://app.example.com'), $this->next());

        $this->assertSame(0, $this->nextCalls);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeader('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization, Accept, X-Requested-With', $response->getHeader('Access-Control-Allow-Headers'));
        $this->assertSame('600', $response->getHeader('Access-Control-Max-Age'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_echoes_requested_headers_when_allowed_headers_is_wildcard(): void
    {
        $request = $this->preflight('https://app.example.com', ['Access-Control-Request-Headers' => 'X-Custom, Content-Type']);

        $response = $this->cors(['allowed_headers' => ['*']])->handle($request, $this->next());

        $this->assertSame('X-Custom, Content-Type', $response->getHeader('Access-Control-Allow-Headers'));
    }

    public function test_preflight_with_wildcard_headers_and_no_requested_headers_omits_allow_headers(): void
    {
        $response = $this->cors(['allowed_headers' => ['*']])->handle($this->preflight('https://app.example.com'), $this->next());

        $this->assertNull($response->getHeader('Access-Control-Allow-Headers'));
    }

    public function test_preflight_with_credentials_sends_credentials_header(): void
    {
        $response = $this->cors(['supports_credentials' => true])->handle($this->preflight('https://app.example.com'), $this->next());

        $this->assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $response->getHeader('Vary'));
    }

    public function test_preflight_from_disallowed_origin_returns_bare_204(): void
    {
        $middleware = $this->cors(['allowed_origins' => ['https://app.example.com']]);

        $response = $middleware->handle($this->preflight('https://evil.example.com'), $this->next());

        $this->assertSame(0, $this->nextCalls);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Methods'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Headers'));
        $this->assertNull($response->getHeader('Access-Control-Max-Age'));
    }

    public function test_options_without_request_method_header_is_not_a_preflight(): void
    {
        $request = Request::create('/api/users', 'OPTIONS', [], ['Origin' => 'https://app.example.com']);

        $response = $this->cors()->handle($request, $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Methods'));
    }

    public function test_origin_with_control_characters_is_never_echoed(): void
    {
        $request = Request::create('/api/users', 'GET', [], ['Origin' => "https://a.example.com\r\nX-Evil: 1"]);

        $response = $this->cors(['supports_credentials' => true])->handle($request, $this->next());

        $this->assertSame(1, $this->nextCalls);
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_requested_headers_with_control_characters_are_not_echoed(): void
    {
        $request = $this->preflight('https://app.example.com', ['Access-Control-Request-Headers' => "X-A\r\nX-Evil: 1"]);

        $response = $this->cors(['allowed_headers' => ['*']])->handle($request, $this->next());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($response->getHeader('Access-Control-Allow-Headers'));
    }
}
