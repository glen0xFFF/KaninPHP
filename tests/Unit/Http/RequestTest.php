<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\HttpException;
use Kanin\Http\Method;
use Kanin\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedGet = [];

    /** @var array<string, mixed> */
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
    }

    public function test_constructor_lowercases_header_names(): void
    {
        $request = new Request('get', '/', [], [], [], ['X-Custom' => 'a', 'ACCEPT' => ['text/html', 'application/json']]);

        $this->assertSame(Method::GET, $request->method());
        $this->assertSame('a', $request->header('x-custom'));
        $this->assertSame('text/html, application/json', $request->header('Accept'));
    }

    public function test_create_parses_query_string_from_uri(): void
    {
        $request = Request::create('/api/users?page=2&per_page=5');

        $this->assertSame(Method::GET, $request->method());
        $this->assertSame('/api/users', $request->path());
        $this->assertSame(['page' => '2', 'per_page' => '5'], $request->query());
        $this->assertSame('2', $request->query('page'));
        $this->assertSame('x', $request->query('missing', 'x'));
    }

    public function test_create_puts_parameters_in_query_for_get_and_head(): void
    {
        $get = Request::create('/search?q=old', 'GET', ['q' => 'new', 'sort' => 'name']);
        $head = Request::create('/search', 'HEAD', ['q' => 'kanin']);

        $this->assertSame(['q' => 'new', 'sort' => 'name'], $get->query());
        $this->assertSame(['q' => 'kanin'], $head->query());
        $this->assertSame(Method::HEAD, $head->method());
    }

    public function test_create_puts_parameters_in_body_for_other_methods(): void
    {
        $request = Request::create('/users?page=1', 'post', ['name' => 'Ada']);

        $this->assertSame(Method::POST, $request->method());
        $this->assertSame(['page' => '1'], $request->query());
        $this->assertSame(['page' => '1', 'name' => 'Ada'], $request->all());
    }

    public function test_create_sets_default_server_values_and_host(): void
    {
        $request = Request::create('/x', 'DELETE');

        $this->assertSame('localhost', $request->host());
        $this->assertSame('localhost', $request->header('Host'));
        $this->assertSame(Method::DELETE, $request->method());
    }

    public function test_create_accepts_server_overrides(): void
    {
        $request = Request::create('/', 'GET', [], [], null, ['HTTP_HOST' => 'api.example.com', 'HTTPS' => 'on']);

        $this->assertSame('api.example.com', $request->host());
        $this->assertTrue($request->isSecure());
    }

    public function test_capture_reads_superglobals(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/api/users/3?debug=1',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '8',
        ];
        $_GET = ['debug' => '1'];
        $_POST = ['name' => 'Ada'];

        $request = Request::capture();

        $this->assertSame(Method::PUT, $request->method());
        $this->assertSame('/api/users/3', $request->path());
        $this->assertSame('XMLHttpRequest', $request->header('X-Requested-With'));
        $this->assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        $this->assertSame('8', $request->header('Content-Length'));
        $this->assertSame('secret', $request->bearerToken());
        $this->assertSame(['debug' => '1', 'name' => 'Ada'], $request->all());
    }

    public function test_capture_defaults_when_server_is_empty(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];

        $request = Request::capture();

        $this->assertSame(Method::GET, $request->method());
        $this->assertSame('/', $request->path());
        $this->assertSame('localhost', $request->host());
    }

    public function test_unknown_method_is_refused_with_405(): void
    {
        $request = Request::create('/', 'TRACE');

        try {
            $request->method();
            $this->fail('Expected HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('Method Not Allowed', $e->getMessage());
        }
    }

    public function test_post_can_be_overridden_by_method_input(): void
    {
        $this->assertSame(Method::PUT, Request::create('/', 'POST', ['_method' => 'PUT'])->method());
        $this->assertSame(Method::PATCH, Request::create('/', 'POST', ['_method' => 'patch'])->method());
        $this->assertSame(Method::DELETE, Request::create('/', 'POST', ['_method' => ' Delete '])->method());
    }

    public function test_post_can_be_overridden_by_header(): void
    {
        $request = Request::create('/', 'POST', [], ['X-HTTP-Method-Override' => 'delete']);

        $this->assertSame(Method::DELETE, $request->method());
    }

    public function test_override_from_json_body_is_honoured(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/json'], '{"_method":"PATCH"}');

        $this->assertSame(Method::PATCH, $request->method());
    }

    public function test_override_to_other_methods_is_ignored(): void
    {
        $this->assertSame(Method::POST, Request::create('/', 'POST', ['_method' => 'GET'])->method());
        $this->assertSame(Method::POST, Request::create('/', 'POST', ['_method' => 'OPTIONS'])->method());
        $this->assertSame(Method::POST, Request::create('/', 'POST', ['_method' => ['PUT']])->method());
        $this->assertSame(
            Method::POST,
            Request::create('/', 'POST', [], ['X-HTTP-Method-Override' => 'TRACE'])->method(),
        );
    }

    public function test_override_only_applies_to_post(): void
    {
        $get = Request::create('/', 'GET', ['_method' => 'DELETE']);
        $put = Request::create('/', 'PUT', [], ['X-HTTP-Method-Override' => 'DELETE']);

        $this->assertSame(Method::GET, $get->method());
        $this->assertSame(Method::PUT, $put->method());
    }

    public function test_path_is_normalized(): void
    {
        $this->assertSame('/', Request::create('')->path());
        $this->assertSame('/', Request::create('/')->path());
        $this->assertSame('/', Request::create('/?a=1')->path());
        $this->assertSame('/api/users', Request::create('/api/users/')->path());
        $this->assertSame('/api/users', Request::create('api/users#top')->path());
        $this->assertSame('/files/my report', Request::create('/files/my%20report')->path());
        $this->assertSame('/a+b', Request::create('/a+b')->path());
        $this->assertSame('/api', Request::create('http://example.com/api/?x=1')->path());
    }

    public function test_json_body_is_decoded_and_merged_over_query(): void
    {
        $request = Request::create(
            '/users?name=query&page=3',
            'POST',
            [],
            ['Content-Type' => 'application/json; charset=utf-8'],
            '{"name":"Ada","tags":["math"]}',
        );

        $this->assertTrue($request->isJson());
        $this->assertSame('Ada', $request->input('name'));
        $this->assertSame(['math'], $request->input('tags'));
        $this->assertSame('3', $request->input('page'));
        $this->assertSame(['name' => 'Ada', 'page' => '3', 'tags' => ['math']], $request->input());
    }

    public function test_plus_json_content_types_are_json(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/vnd.api+json'], '{"a":1}');

        $this->assertTrue($request->isJson());
        $this->assertSame(1, $request->input('a'));
    }

    public function test_non_json_content_type_ignores_raw_content(): void
    {
        $request = Request::create('/', 'POST', ['a' => '1'], ['Content-Type' => 'text/plain'], '{"b":2}');

        $this->assertFalse($request->isJson());
        $this->assertSame(['a' => '1'], $request->all());
    }

    public function test_empty_json_body_gives_form_input(): void
    {
        $request = Request::create('/', 'POST', ['a' => '1'], ['Content-Type' => 'application/json'], '');

        $this->assertSame(['a' => '1'], $request->all());
    }

    public function test_malformed_json_body_is_a_400(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/json'], '{"name": ');

        try {
            $request->input('name');
            $this->fail('Expected HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Malformed JSON body.', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function test_json_scalar_body_is_a_400(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/json'], '"just a string"');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Malformed JSON body.');

        $request->all();
    }

    public function test_malformed_json_breaks_method_override_lookup_with_400(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/json'], 'not json');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Malformed JSON body.');

        $request->method();
    }

    public function test_input_returns_default_for_missing_key_but_keeps_null_values(): void
    {
        $request = Request::create('/', 'POST', [], ['Content-Type' => 'application/json'], '{"nick":null}');

        $this->assertSame('fallback', $request->input('missing', 'fallback'));
        $this->assertNull($request->input('nick', 'fallback'));
    }

    public function test_only_except_and_has(): void
    {
        $request = Request::create('/', 'POST', ['name' => 'Ada', 'email' => 'ada@example.com', 'role' => '']);

        $this->assertSame(['name' => 'Ada'], $request->only('name', 'missing'));
        $this->assertSame(['name' => 'Ada', 'role' => ''], $request->except('email'));
        $this->assertTrue($request->has('role'));
        $this->assertFalse($request->has('missing'));
    }

    public function test_header_is_case_insensitive_with_default(): void
    {
        $request = Request::create('/', 'GET', [], ['Accept' => 'application/json']);

        $this->assertSame('application/json', $request->header('ACCEPT'));
        $this->assertNull($request->header('X-Missing'));
        $this->assertSame('none', $request->header('X-Missing', 'none'));
    }

    public function test_bearer_token_is_extracted(): void
    {
        $request = Request::create('/', 'GET', [], ['Authorization' => 'Bearer abc123']);

        $this->assertSame('abc123', $request->bearerToken());
    }

    public function test_bearer_scheme_is_case_insensitive_and_token_trimmed(): void
    {
        $request = Request::create('/', 'GET', [], ['authorization' => '  bearer    abc123  ']);

        $this->assertSame('abc123', $request->bearerToken());
    }

    public function test_bearer_token_is_null_when_missing_empty_or_other_scheme(): void
    {
        $this->assertNull(Request::create('/')->bearerToken());
        $this->assertNull(Request::create('/', 'GET', [], ['Authorization' => 'Bearer'])->bearerToken());
        $this->assertNull(Request::create('/', 'GET', [], ['Authorization' => 'Bearer    '])->bearerToken());
        $this->assertNull(Request::create('/', 'GET', [], ['Authorization' => 'Basic dXNlcjpwYXNz'])->bearerToken());
        $this->assertNull(Request::create('/', 'GET', [], ['Authorization' => 'Bearerabc'])->bearerToken());
    }

    public function test_is_secure_checks_https_server_value(): void
    {
        $this->assertFalse(Request::create('/')->isSecure());
        $this->assertFalse(Request::create('/', 'GET', [], [], null, ['HTTPS' => 'off'])->isSecure());
        $this->assertFalse(Request::create('/', 'GET', [], [], null, ['HTTPS' => ''])->isSecure());
        $this->assertTrue(Request::create('/', 'GET', [], [], null, ['HTTPS' => '1'])->isSecure());
    }

    public function test_host_falls_back_to_server_name_then_localhost(): void
    {
        $fromHeader = new Request('GET', '/', [], [], ['SERVER_NAME' => 'server.test'], ['Host' => 'header.test:8000']);
        $fromServer = new Request('GET', '/', [], [], ['SERVER_NAME' => 'server.test']);
        $fallback = new Request('GET', '/');

        $this->assertSame('header.test:8000', $fromHeader->host());
        $this->assertSame('server.test', $fromServer->host());
        $this->assertSame('localhost', $fallback->host());
    }

    public function test_route_parameters(): void
    {
        $request = Request::create('/users/5');

        $this->assertSame([], $request->route());
        $this->assertNull($request->route('id'));

        $request->setRouteParameters(['id' => '5']);

        $this->assertSame(['id' => '5'], $request->route());
        $this->assertSame('5', $request->route('id'));
        $this->assertSame('d', $request->route('missing', 'd'));
    }
}
