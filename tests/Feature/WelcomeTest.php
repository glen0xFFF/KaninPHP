<?php

declare(strict_types=1);

namespace Tests\Feature;

use Kanin\Application;
use Tests\TestCase;

final class WelcomeTest extends TestCase
{
    public function test_api_root_returns_the_app_name_and_version(): void
    {
        $response = $this->getJson('/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(
            ['name' => config('app.name'), 'version' => Application::VERSION],
            $this->decode($response)
        );
        $this->assertSame('1.0.0', $this->decode($response)['version']);
    }

    public function test_api_root_accepts_a_trailing_slash(): void
    {
        $response = $this->getJson('/api/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Application::VERSION, $this->decode($response)['version']);
    }

    public function test_head_request_is_answered_by_the_get_route(): void
    {
        $response = $this->json('HEAD', '/api');

        $this->assertSame(200, $response->getStatusCode());
    }
}
