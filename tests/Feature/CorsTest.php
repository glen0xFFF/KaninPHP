<?php

declare(strict_types=1);

namespace Tests\Feature;

use Kanin\Http\Request;
use Tests\TestCase;

final class CorsTest extends TestCase
{
    public function test_preflight_request_is_answered_with_204_and_cors_headers(): void
    {
        $request = Request::create('/api/users', 'OPTIONS', [], [
            'Origin' => 'https://frontend.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, Authorization',
        ]);

        $response = $this->app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeader('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization, Accept, X-Requested-With', $response->getHeader('Access-Control-Allow-Headers'));
        $this->assertSame('600', $response->getHeader('Access-Control-Max-Age'));
        $this->assertNull($response->getHeader('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_for_a_protected_route_does_not_need_a_token(): void
    {
        $request = Request::create('/api/users/1', 'OPTIONS', [], [
            'Origin' => 'https://frontend.example',
            'Access-Control-Request-Method' => 'DELETE',
        ]);

        $response = $this->app->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_normal_request_with_origin_gets_the_allow_origin_header(): void
    {
        $response = $this->getJson('/api', ['Origin' => 'https://frontend.example']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_error_responses_also_carry_cors_headers(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'Eve'], ['Origin' => 'https://frontend.example']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_request_without_origin_gets_no_cors_headers(): void
    {
        $response = $this->getJson('/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }
}
