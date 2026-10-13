<?php

declare(strict_types=1);

namespace Tests;

use Kanin\Application;
use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Database\Model;
use Kanin\Http\Request;
use Kanin\Http\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for feature tests: boots the real application against a fresh
 * in-memory SQLite database (seeded from database/seed.sql) for every test,
 * and offers helpers to send JSON requests through Application::handle().
 */
abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    /**
     * Headers added to every request (filled by withToken()).
     *
     * @var array<string, string>
     */
    private array $defaultHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__));
        $this->app->boot();

        // A brand-new database per test, so tests never affect each other.
        $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->app->instance(Connection::class, $connection);
        $connection->unprepared((string) file_get_contents($this->app->basePath('database/seed.sql')));

        Model::setConnection(null);
    }

    protected function tearDown(): void
    {
        // boot() installed the framework's PHP error handler; remove it so
        // PHPUnit does not flag the test as risky.
        restore_error_handler();

        Container::setInstance(null);
        Model::setConnection(null);

        parent::tearDown();
    }

    /**
     * Send a JSON request through the whole application and return the response.
     *
     * GET requests put $data in the query string; other methods send it as a JSON body.
     */
    protected function json(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        $method = strtoupper($method);
        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $this->defaultHeaders,
            $headers,
        );

        if ($method === 'GET') {
            return $this->app->handle(Request::create($uri, $method, $data, $headers));
        }

        $content = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->app->handle(Request::create($uri, $method, [], $headers, $content));
    }

    /**
     * Send a GET request.
     */
    protected function getJson(string $uri, array $headers = []): Response
    {
        return $this->json('GET', $uri, [], $headers);
    }

    /**
     * Send a POST request with a JSON body.
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->json('POST', $uri, $data, $headers);
    }

    /**
     * Send a PUT request with a JSON body.
     */
    protected function putJson(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->json('PUT', $uri, $data, $headers);
    }

    /**
     * Send a PATCH request with a JSON body.
     */
    protected function patchJson(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->json('PATCH', $uri, $data, $headers);
    }

    /**
     * Send a DELETE request.
     */
    protected function deleteJson(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->json('DELETE', $uri, $data, $headers);
    }

    /**
     * Send "Authorization: Bearer <token>" with every following request.
     *
     * 'test-token' is the token phpunit.xml puts in API_TOKENS.
     */
    protected function withToken(string $token = 'test-token'): static
    {
        $this->defaultHeaders['Authorization'] = 'Bearer ' . $token;

        return $this;
    }

    /**
     * Decode a response's JSON body into arrays (null for an empty body).
     */
    protected function decode(Response $response): mixed
    {
        $content = $response->getContent();

        return $content === '' ? null : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
