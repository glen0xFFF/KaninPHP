<?php

declare(strict_types=1);

namespace Tests\Feature;

use Kanin\Http\Request;
use Tests\TestCase;

final class HttpErrorsTest extends TestCase
{
    public function test_unknown_route_returns_404_json(): void
    {
        $response = $this->getJson('/api/does-not-exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(['message' => 'Not Found'], $this->decode($response));
    }

    public function test_route_outside_the_api_prefix_returns_404(): void
    {
        $response = $this->getJson('/users');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Not Found'], $this->decode($response));
    }

    public function test_wrong_method_returns_405_with_allow_header(): void
    {
        $response = $this->deleteJson('/api');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['message' => 'Method Not Allowed'], $this->decode($response));
        $this->assertStringContainsString('GET', (string) $response->getHeader('Allow'));
    }

    public function test_wrong_method_on_a_resource_lists_the_allowed_methods(): void
    {
        $response = $this->putJson('/api/users', ['name' => 'Nobody']);

        $this->assertSame(405, $response->getStatusCode());
        $allow = (string) $response->getHeader('Allow');
        $this->assertStringContainsString('GET', $allow);
        $this->assertStringContainsString('POST', $allow);
        $this->assertStringNotContainsString('PUT', $allow);
    }

    public function test_malformed_json_body_returns_400(): void
    {
        $request = Request::create('/api/users', 'POST', [], [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer test-token',
        ], '{"name": "Ada", "email":');

        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['message' => 'Malformed JSON body.'], $this->decode($response));
        $this->assertSame(3, $this->decode($this->getJson('/api/users'))['meta']['total']);
    }

    public function test_json_body_that_is_not_an_object_returns_400(): void
    {
        $request = Request::create('/api/users', 'POST', [], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer test-token',
        ], '"just a string"');

        $response = $this->app->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['message' => 'Malformed JSON body.'], $this->decode($response));
    }
}
