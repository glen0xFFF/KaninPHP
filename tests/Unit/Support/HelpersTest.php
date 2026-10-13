<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Database\QueryBuilder;
use Kanin\Http\HttpException;
use Kanin\Http\JsonResponse;
use Kanin\Http\NotFoundHttpException;
use Kanin\Http\Request;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance(Config::class, new Config([
            'app' => ['name' => 'Helper App', 'url' => 'http://example.test/'],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        unset($_ENV['KANIN_HELPER_FLAG']);
    }

    public function test_app_returns_the_container_or_a_service(): void
    {
        $this->assertSame($this->container, app());
        $this->assertSame($this->container->make(Config::class), app(Config::class));
    }

    public function test_app_passes_parameters_to_make(): void
    {
        $fresh = app(\ArrayObject::class, ['array' => ['a' => 1]]);
        $this->assertSame(['a' => 1], $fresh->getArrayCopy());
    }

    public function test_config_reads_dot_notation_with_default(): void
    {
        $this->assertSame('Helper App', config('app.name'));
        $this->assertSame('fallback', config('app.missing', 'fallback'));
        $this->assertSame($this->container->make(Config::class), config());
    }

    public function test_env_reads_and_casts_environment_values(): void
    {
        $_ENV['KANIN_HELPER_FLAG'] = 'true';

        $this->assertTrue(env('KANIN_HELPER_FLAG'));
        $this->assertSame('default', env('KANIN_HELPER_MISSING', 'default'));
    }

    public function test_response_builds_a_json_response(): void
    {
        $response = response(['id' => 1], 201, ['X-Test' => 'yes']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"id":1}', $response->getContent());
        $this->assertSame('yes', $response->getHeader('X-Test'));
    }

    public function test_response_with_no_arguments_is_json_null_200(): void
    {
        $response = response();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('null', $response->getContent());
    }

    public function test_route_builds_a_named_route_url(): void
    {
        $router = new Router($this->container);
        $this->container->instance(Router::class, $router);
        $router->get('/users/{id}', fn () => null)->name('users.show');

        $this->assertSame('/users/5', route('users.show', ['id' => 5]));
    }

    public function test_route_with_unknown_name_throws(): void
    {
        $this->container->instance(Router::class, new Router($this->container));

        $this->expectException(\InvalidArgumentException::class);

        route('nope');
    }

    public function test_url_joins_the_app_url_and_a_path(): void
    {
        $this->assertSame('http://example.test', url());
        $this->assertSame('http://example.test/api/users', url('/api/users'));
        $this->assertSame('http://example.test/api', url('api'));
    }

    public function test_request_returns_the_current_request_or_an_input_value(): void
    {
        $request = Request::create('/users?page=2');
        $this->container->instance(Request::class, $request);

        $this->assertSame($request, request());
        $this->assertSame('2', request('page'));
        $this->assertSame(15, request('per_page', 15));
    }

    public function test_db_returns_the_connection_or_a_query_builder(): void
    {
        $connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->container->instance(Connection::class, $connection);

        $this->assertSame($connection, db());
        $this->assertInstanceOf(QueryBuilder::class, db('users'));
    }

    public function test_abort_404_throws_not_found(): void
    {
        try {
            abort(404);
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Not Found', $e->getMessage());
        }
    }

    public function test_abort_404_keeps_a_custom_message(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('User not found.');

        abort(404, 'User not found.');
    }

    public function test_abort_other_codes_throw_http_exception_with_headers(): void
    {
        try {
            abort(403, 'Forbidden.', ['X-Reason' => 'policy']);
            $this->fail('Expected HttpException.');
        } catch (HttpException $e) {
            $this->assertNotInstanceOf(NotFoundHttpException::class, $e);
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Forbidden.', $e->getMessage());
            $this->assertSame(['X-Reason' => 'policy'], $e->getHeaders());
        }
    }

    public function test_dd_dumps_every_value_and_exits_with_code_1(): void
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . "; dd('first', 42);";
        $output = [];
        $exitCode = null;

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exitCode);

        $text = implode("\n", $output);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('string(5) "first"', $text);
        $this->assertStringContainsString('int(42)', $text);
    }

    public function test_every_helper_is_defined(): void
    {
        foreach (['app', 'config', 'env', 'response', 'route', 'url', 'request', 'db', 'abort', 'dd'] as $function) {
            $this->assertTrue(function_exists($function), "Helper [{$function}] is missing.");
        }
    }
}
