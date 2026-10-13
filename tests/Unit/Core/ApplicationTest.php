<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Kanin\Application;
use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Exceptions\Handler;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Core\Fixtures\AddHeaderMiddleware;
use Tests\Unit\Core\Fixtures\ThrowingMiddleware;

final class ApplicationTest extends TestCase
{
    private string $root;

    private mixed $originalErrorHandler;

    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/kanin-app-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);

        $this->writeConfig('app', ['name' => 'Test App', 'debug' => false, 'timezone' => 'UTC', 'middleware' => [], 'middleware_aliases' => []]);
        $this->writeConfig('database', ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);

        $this->originalErrorHandler = $this->currentErrorHandler();
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // boot() installs Handler's error handler; remove it so PHPUnit does not flag the test as risky.
        for ($i = 0; $i < 10 && $this->currentErrorHandler() !== $this->originalErrorHandler; $i++) {
            restore_error_handler();
        }

        date_default_timezone_set($this->originalTimezone);
        unset($_ENV['KANIN_APP_TEST_NAME'], $_SERVER['KANIN_APP_TEST_NAME']);
        putenv('KANIN_APP_TEST_NAME');
        Container::setInstance(null);
        $this->deleteDirectory($this->root);
    }

    public function test_version_is_1_0_0(): void
    {
        $this->assertSame('1.0.0', Application::VERSION);
    }

    public function test_base_path_is_normalized(): void
    {
        $app = new Application('C:\\Projects\\Kanin\\');

        $this->assertSame('C:/Projects/Kanin', $app->basePath());
        $this->assertSame('C:/Projects/Kanin/config', $app->basePath('config'));
        $this->assertSame('C:/Projects/Kanin/routes/api.php', $app->basePath('/routes/api.php'));
    }

    public function test_constructor_registers_the_app_as_the_global_container(): void
    {
        $app = new Application($this->root);

        $this->assertInstanceOf(Container::class, $app);
        $this->assertSame($app, Container::getInstance());
        $this->assertSame($app, $app->make(Application::class));
        $this->assertSame($app, $app->make(Container::class));
    }

    public function test_boot_loads_the_env_file_before_the_config_files(): void
    {
        file_put_contents($this->root . '/.env', "KANIN_APP_TEST_NAME=FromDotEnv\n");
        $this->writeFile('config/app.php', "<?php\n\nreturn ['name' => env('KANIN_APP_TEST_NAME', 'fallback')];\n");

        $app = $this->bootedApp();

        $this->assertSame('FromDotEnv', $app->make(Config::class)->get('app.name'));
    }

    public function test_boot_sets_the_configured_timezone(): void
    {
        $this->writeConfig('app', ['timezone' => 'Asia/Manila']);

        $this->bootedApp();

        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function test_boot_defaults_the_timezone_to_utc(): void
    {
        $this->writeConfig('app', []);
        date_default_timezone_set('Asia/Tokyo');

        $this->bootedApp();

        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_boot_registers_the_core_services_as_singletons(): void
    {
        $app = $this->bootedApp();

        $this->assertInstanceOf(Router::class, $app->make(Router::class));
        $this->assertSame($app->make(Router::class), $app->make(Router::class));
        $this->assertInstanceOf(Handler::class, $app->make(Handler::class));
        $this->assertSame($app->make(Handler::class), $app->make(Handler::class));
        $this->assertSame($app->make(Connection::class), $app->make(Connection::class));
        $this->assertSame('sqlite', $app->make(Connection::class)->getDriverName());
    }

    public function test_unconfigured_default_connection_throws_when_used(): void
    {
        $this->writeConfig('database', ['default' => 'oracle', 'connections' => []]);
        $app = $this->bootedApp();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection [oracle] is not configured.');

        $app->make(Connection::class);
    }

    public function test_boot_installs_the_exception_handlers_error_handler(): void
    {
        // PHPUnit lowers error_reporting() while a test runs, so we only check that
        // boot() pushed a new handler; HandlerTest covers what that handler does.
        $this->bootedApp();

        $this->assertNotSame($this->originalErrorHandler, $this->currentErrorHandler());
    }

    public function test_boot_only_runs_once(): void
    {
        $this->writeRoutes("\$router->get('/ping', fn () => ['pong' => true]);");
        $app = $this->bootedApp();
        $handlerAfterFirstBoot = $this->currentErrorHandler();

        $app->boot();

        $this->assertCount(1, $app->make(Router::class)->routes());
        $this->assertSame($handlerAfterFirstBoot, $this->currentErrorHandler());
    }

    public function test_routes_file_is_loaded_under_the_api_prefix(): void
    {
        $this->writeRoutes("\$router->get('/ping', fn () => ['pong' => true]);");
        $app = $this->bootedApp();

        $this->assertSame('/api/ping', array_values($app->make(Router::class)->routes())[0]->uri());

        $response = $app->handle(Request::create('/api/ping'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pong' => true], json_decode($response->getContent(), true));
    }

    public function test_missing_routes_file_gives_json_404(): void
    {
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(['message' => 'Not Found'], json_decode($response->getContent(), true));
    }

    public function test_middleware_aliases_from_config_are_registered(): void
    {
        $this->writeConfig('app', ['middleware_aliases' => ['fixture.header' => AddHeaderMiddleware::class]]);
        $this->writeRoutes("\$router->get('/ping', fn () => ['pong' => true])->middleware('fixture.header');");
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api/ping'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('seen', $response->getHeader('X-Fixture'));
    }

    public function test_global_middleware_wraps_every_response_including_errors(): void
    {
        $this->writeConfig('app', ['middleware' => [AddHeaderMiddleware::class]]);
        $this->writeRoutes("\$router->get('/ping', fn () => ['pong' => true]);");
        $app = new Application($this->root);

        $ok = $app->handle(Request::create('/api/ping'));
        $missing = $app->handle(Request::create('/api/nope'));

        $this->assertSame('seen', $ok->getHeader('X-Fixture'));
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('seen', $missing->getHeader('X-Fixture'));
    }

    public function test_exception_in_production_is_logged_and_rendered_without_details(): void
    {
        $this->writeRoutes("\$router->get('/boom', fn () => throw new \\RuntimeException('secret detail'));");
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api/boom'));

        $this->assertFalse($app->isDebug());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Server Error'], json_decode($response->getContent(), true));
        $this->assertStringContainsString('RuntimeException: secret detail', (string) file_get_contents($this->root . '/storage/logs/kanin.log'));
    }

    public function test_exception_in_debug_mode_shows_the_real_message(): void
    {
        $this->writeConfig('app', ['debug' => true]);
        $this->writeRoutes("\$router->get('/boom', fn () => throw new \\RuntimeException('secret detail'));");
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api/boom'));
        $body = json_decode($response->getContent(), true);

        $this->assertTrue($app->isDebug());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('secret detail', $body['message']);
        $this->assertSame(\RuntimeException::class, $body['exception']);
    }

    public function test_exception_thrown_by_global_middleware_is_rendered(): void
    {
        $this->writeConfig('app', ['middleware' => [ThrowingMiddleware::class]]);
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api'));

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame(['message' => 'I am a teapot.'], json_decode($response->getContent(), true));
    }

    public function test_failure_while_booting_is_rendered_as_a_generic_server_error(): void
    {
        $this->writeFile('config/broken.php', "<?php\n\nthrow new \\RuntimeException('config exploded');\n");
        $app = new Application($this->root);

        $response = $app->handle(Request::create('/api'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Server Error'], json_decode($response->getContent(), true));
    }

    public function test_handle_binds_the_current_request_in_the_container(): void
    {
        $app = new Application($this->root);
        $request = Request::create('/api/anything');

        $app->handle($request);

        $this->assertSame($request, $app->make(Request::class));
    }

    public function test_is_debug_is_false_before_boot_and_when_not_configured(): void
    {
        $this->writeConfig('app', []);
        $app = new Application($this->root);

        $this->assertFalse($app->isDebug());

        $app->boot();

        $this->assertFalse($app->isDebug());
    }

    public function test_run_captures_handles_and_sends_the_response(): void
    {
        $this->writeRoutes("\$router->get('/ping', fn () => ['pong' => true]);");
        $app = new Application($this->root);
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/ping';

        try {
            ob_start();
            $app->run();
            $output = (string) ob_get_clean();
        } finally {
            $_SERVER = $server;
        }

        $this->assertSame('{"pong":true}', $output);
    }

    private function bootedApp(): Application
    {
        $app = new Application($this->root);
        $app->boot();

        return $app;
    }

    private function writeConfig(string $name, array $values): void
    {
        $this->writeFile("config/{$name}.php", "<?php\n\nreturn " . var_export($values, true) . ";\n");
    }

    private function writeRoutes(string $code): void
    {
        $this->writeFile('routes/api.php', "<?php\n\n{$code}\n");
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function currentErrorHandler(): mixed
    {
        $current = set_error_handler(null);
        restore_error_handler();

        return $current;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
