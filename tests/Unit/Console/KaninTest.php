<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Kanin\Application;
use Kanin\Console\Kanin;
use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Http\JsonResponse;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KaninTest extends TestCase
{
    private string $basePath;

    private ?Application $app = null;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test gets its own empty project folder, so generators never touch the real app/.
        $this->basePath = str_replace('\\', '/', sys_get_temp_dir()) . '/kanin-console-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0777, true);

        $this->app = new Application($this->basePath);
        $this->stream = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
        Container::setInstance(null);
        $this->app = null;
        gc_collect_cycles(); // release SQLite file handles before deleting the folder (Windows)
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_help_is_the_default_command(): void
    {
        $this->assertSame(0, $this->kanin());

        $output = $this->printed();
        $this->assertStringContainsString('KaninPHP ' . Application::VERSION, $output);
        $this->assertStringContainsString('serve', $output);
        $this->assertStringContainsString('routes', $output);
        $this->assertStringContainsString('make:controller Name [--api]', $output);
        $this->assertStringContainsString('make:model Name', $output);
        $this->assertStringContainsString('make:middleware Name', $output);
        $this->assertStringContainsString('db:seed', $output);
    }

    #[DataProvider('helpAliases')]
    public function test_help_aliases_print_the_command_list(string $alias): void
    {
        $this->assertSame(0, $this->kanin($alias));
        $this->assertStringContainsString('Commands:', $this->printed());
    }

    public static function helpAliases(): array
    {
        return ['help' => ['help'], '--help' => ['--help'], '-h' => ['-h']];
    }

    public function test_unknown_command_fails(): void
    {
        $this->assertSame(1, $this->kanin('migrate'));
        $this->assertStringContainsString('Unknown command [migrate].', $this->printed());
    }

    public function test_make_controller_creates_the_class_from_the_stub(): void
    {
        $name = $this->uniqueName('Controller');

        $this->assertSame(0, $this->kanin('make:controller', $name));

        $relative = "app/Controllers/{$name}.php";
        $this->assertStringContainsString("Created {$relative}", $this->printed());
        $this->assertFileExists($this->basePath . '/' . $relative);

        $code = (string) file_get_contents($this->basePath . '/' . $relative);
        $this->assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", $code);
        $this->assertStringContainsString('namespace App\Controllers;', $code);
        $this->assertStringContainsString("class {$name}", $code);
        $this->assertStringNotContainsString('{{ class }}', $code);

        require $this->basePath . '/' . $relative;
        $class = "App\\Controllers\\{$name}";
        $this->assertSame(['message' => "{$name} works!"], (new $class())->index(Request::create('/')));
    }

    public function test_make_controller_with_api_flag_creates_all_resource_actions(): void
    {
        $name = $this->uniqueName('Controller');

        $this->assertSame(0, $this->kanin('make:controller', $name, '--api'));

        $relative = "app/Controllers/{$name}.php";
        require $this->basePath . '/' . $relative;
        $class = "App\\Controllers\\{$name}";
        $controller = new $class();

        $this->assertSame(['data' => []], $controller->index(Request::create('/things')));
        $this->assertSame(['id' => 7], $controller->show(7));
        $this->assertSame(['id' => 7], $controller->update(Request::create('/things/7', 'PUT'), 7));

        $created = $controller->store(Request::create('/things', 'POST'));
        $this->assertInstanceOf(JsonResponse::class, $created);
        $this->assertSame(201, $created->getStatusCode());

        $deleted = $controller->destroy(7);
        $this->assertInstanceOf(JsonResponse::class, $deleted);
        $this->assertSame(204, $deleted->getStatusCode());
    }

    public function test_make_model_creates_a_model_class(): void
    {
        $name = $this->uniqueName('Model');

        $this->assertSame(0, $this->kanin('make:model', $name));
        $this->assertStringContainsString("Created app/Models/{$name}.php", $this->printed());

        require $this->basePath . "/app/Models/{$name}.php";
        $class = "App\\Models\\{$name}";
        $this->assertInstanceOf(\Kanin\Database\Model::class, new $class());
    }

    public function test_make_middleware_creates_a_pass_through_middleware(): void
    {
        $name = $this->uniqueName('Middleware');

        $this->assertSame(0, $this->kanin('make:middleware', $name));
        $this->assertStringContainsString("Created app/Middleware/{$name}.php", $this->printed());

        require $this->basePath . "/app/Middleware/{$name}.php";
        $class = "App\\Middleware\\{$name}";
        $middleware = new $class();
        $this->assertInstanceOf(\Kanin\Http\Middleware::class, $middleware);

        $response = $middleware->handle(Request::create('/'), fn (Request $request): Response => new Response('passed', 200));
        $this->assertSame('passed', $response->getContent());
    }

    public function test_generators_create_the_target_directory_when_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->basePath . '/app');

        $this->assertSame(0, $this->kanin('make:model', 'Invoice'));

        $this->assertDirectoryExists($this->basePath . '/app/Models');
        $this->assertFileExists($this->basePath . '/app/Models/Invoice.php');
    }

    public function test_generators_refuse_to_overwrite_an_existing_file(): void
    {
        mkdir($this->basePath . '/app/Controllers', 0777, true);
        file_put_contents($this->basePath . '/app/Controllers/PostController.php', 'original');

        $this->assertSame(1, $this->kanin('make:controller', 'PostController'));

        $this->assertStringContainsString('app/Controllers/PostController.php already exists.', $this->printed());
        $this->assertSame('original', file_get_contents($this->basePath . '/app/Controllers/PostController.php'));
    }

    #[DataProvider('invalidNames')]
    public function test_generators_reject_invalid_class_names(string $name): void
    {
        $this->assertSame(1, $this->kanin('make:controller', $name));

        $this->assertStringContainsString("Invalid name [{$name}].", $this->printed());
        $this->assertDirectoryDoesNotExist($this->basePath . '/app');
    }

    public static function invalidNames(): array
    {
        return [
            'lowercase first letter' => ['post'],
            'starts with a digit' => ['1Post'],
            'path traversal' => ['../Evil'],
            'sub folder' => ['Admin/Post'],
            'backslash' => ['Admin\\Post'],
            'dash' => ['Bad-Name'],
            'file extension' => ['Post.php'],
            'space' => ['Post Controller'],
        ];
    }

    #[DataProvider('generatorCommands')]
    public function test_generators_require_a_name(string $command): void
    {
        $this->assertSame(1, $this->kanin($command));
        $this->assertStringContainsString("Missing class name. Example: php kanin {$command} Name", $this->printed());
    }

    public static function generatorCommands(): array
    {
        return [
            'make:controller' => ['make:controller'],
            'make:model' => ['make:model'],
            'make:middleware' => ['make:middleware'],
        ];
    }

    public function test_routes_prints_a_table_of_every_route(): void
    {
        $router = $this->app->instance(Router::class, new Router($this->app));
        $router->get('/users', ['App\Controllers\UserController', 'index'])->name('users.index');
        $router->post('/users', fn () => null);
        $router->delete('/users/{id}', 'App\Controllers\UserController@destroy');

        $this->assertSame(0, $this->kanin('routes'));

        $lines = explode(PHP_EOL, trim($this->printed()));
        $this->assertMatchesRegularExpression('/^METHOD\s+\|\s+URI\s+\|\s+NAME\s+\|\s+ACTION$/', $lines[0]);
        $this->assertMatchesRegularExpression('/^-+-\+-+-\+-+-\+-+$/', $lines[1]);
        $this->assertMatchesRegularExpression('/^GET\s+\|\s+\/users\s+\|\s+users\.index\s+\|\s+App\\\\Controllers\\\\UserController@index$/', $lines[2]);
        $this->assertMatchesRegularExpression('/^POST\s+\|\s+\/users\s+\|\s+\|\s+Closure$/', $lines[3]);
        $this->assertMatchesRegularExpression('/^DELETE\s+\|\s+\/users\/\{id\}\s+\|\s+\|\s+App\\\\Controllers\\\\UserController@destroy$/', $lines[4]);
        $this->assertCount(5, $lines);
    }

    public function test_routes_joins_multiple_methods_with_a_pipe(): void
    {
        $router = $this->app->instance(Router::class, new Router($this->app));
        $router->match(['PUT', 'PATCH'], '/users/{id}', fn () => null);

        $this->assertSame(0, $this->kanin('routes'));
        $this->assertStringContainsString('PUT|PATCH', $this->printed());
    }

    public function test_routes_says_so_when_there_are_no_routes(): void
    {
        $this->app->instance(Router::class, new Router($this->app));

        $this->assertSame(0, $this->kanin('routes'));
        $this->assertStringContainsString('No routes registered.', $this->printed());
    }

    public function test_serve_starts_the_built_in_server_with_escaped_arguments(): void
    {
        $console = $this->recordingConsole();

        $this->assertSame(0, $console->run(['kanin', 'serve']));

        $expected = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-S',
            '127.0.0.1:8000',
            '-t',
            $this->basePath . '/public',
            $this->basePath . '/public/index.php',
        ]));
        $this->assertSame([$expected], $console->commands);
        $this->assertStringContainsString('http://127.0.0.1:8000', $this->printed());
    }

    public function test_serve_accepts_a_custom_host_and_port(): void
    {
        $console = $this->recordingConsole();

        $this->assertSame(0, $console->run(['kanin', 'serve', '--host=0.0.0.0', '--port=8080']));

        $this->assertCount(1, $console->commands);
        $this->assertStringContainsString(escapeshellarg('0.0.0.0:8080'), $console->commands[0]);
    }

    public function test_serve_returns_the_server_exit_code(): void
    {
        $console = $this->recordingConsole(exitCode: 255);

        $this->assertSame(255, $console->run(['kanin', 'serve']));
    }

    #[DataProvider('invalidHosts')]
    public function test_serve_rejects_an_invalid_host(string $option): void
    {
        $console = $this->recordingConsole();

        $this->assertSame(1, $console->run(['kanin', 'serve', $option]));

        $this->assertSame([], $console->commands);
        $this->assertStringContainsString('Invalid host.', $this->printed());
    }

    public static function invalidHosts(): array
    {
        return [
            'shell injection' => ['--host=127.0.0.1;whoami'],
            'space' => ['--host=local host'],
            'empty' => ['--host='],
            'flag without value' => ['--host'],
        ];
    }

    #[DataProvider('invalidPorts')]
    public function test_serve_rejects_an_invalid_port(string $option): void
    {
        $console = $this->recordingConsole();

        $this->assertSame(1, $console->run(['kanin', 'serve', $option]));

        $this->assertSame([], $console->commands);
        $this->assertStringContainsString('Invalid port.', $this->printed());
    }

    public static function invalidPorts(): array
    {
        return [
            'zero' => ['--port=0'],
            'too big' => ['--port=65536'],
            'not a number' => ['--port=http'],
            'negative' => ['--port=-1'],
            'shell injection' => ['--port=8000&calc'],
            'flag without value' => ['--port'],
        ];
    }

    public function test_db_seed_creates_the_sqlite_file_and_runs_the_seed(): void
    {
        $database = $this->basePath . '/storage/db/app.sqlite';
        $this->useSqliteDatabase($database);
        mkdir($this->basePath . '/database');
        file_put_contents(
            $this->basePath . '/database/seed.sql',
            "CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL);\nINSERT INTO notes (body) VALUES ('one'), ('two');"
        );

        $this->assertFileDoesNotExist($database);
        $this->assertSame(0, $this->kanin('db:seed'));

        $this->assertFileExists($database);
        $this->assertStringContainsString("Created SQLite database {$database}", $this->printed());
        $this->assertStringContainsString('Database seeded from database/seed.sql', $this->printed());

        $count = $this->app->make(Connection::class)->selectOne('select count(*) as total from notes');
        $this->assertSame(2, (int) $count['total']);
    }

    public function test_db_seed_reuses_an_existing_sqlite_file(): void
    {
        $database = $this->basePath . '/database/app.sqlite';
        $this->useSqliteDatabase($database);
        mkdir($this->basePath . '/database');
        touch($database);
        file_put_contents($this->basePath . '/database/seed.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->kanin('db:seed'));

        $this->assertStringNotContainsString('Created SQLite database', $this->printed());
        $this->assertStringContainsString('Database seeded', $this->printed());
    }

    public function test_db_seed_fails_when_the_seed_file_is_missing(): void
    {
        $database = $this->basePath . '/database/app.sqlite';
        $this->useSqliteDatabase($database);

        $this->assertSame(1, $this->kanin('db:seed'));

        $this->assertStringContainsString('Seed file not found: database/seed.sql', $this->printed());
        $this->assertFileDoesNotExist($database);
    }

    public function test_command_errors_are_printed_instead_of_thrown(): void
    {
        $this->useSqliteDatabase($this->basePath . '/database/app.sqlite');
        mkdir($this->basePath . '/database');
        file_put_contents($this->basePath . '/database/seed.sql', 'THIS IS NOT SQL;');

        $this->assertSame(1, $this->kanin('db:seed'));
        $this->assertStringContainsString('Error: ', $this->printed());
    }

    /**
     * Run the console with the given arguments (the script name is added for you).
     */
    private function kanin(string ...$arguments): int
    {
        return (new Kanin($this->app, $this->stream))->run(['kanin', ...$arguments]);
    }

    /**
     * Everything the console has printed so far.
     */
    private function printed(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * A console that records shell commands instead of running them.
     */
    private function recordingConsole(int $exitCode = 0): Kanin
    {
        return new class ($this->app, $this->stream, $exitCode) extends Kanin {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(Application $app, $output, private readonly int $exitCode)
            {
                parent::__construct($app, $output);
            }

            protected function execute(string $command): int
            {
                $this->commands[] = $command;

                return $this->exitCode;
            }
        };
    }

    /**
     * Point the app at a SQLite file inside the temporary project.
     */
    private function useSqliteDatabase(string $path): void
    {
        $settings = ['driver' => 'sqlite', 'database' => $path];

        $this->app->instance(Config::class, new Config([
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => $settings]],
        ]));
        $this->app->instance(Connection::class, new Connection($settings));
    }

    /**
     * A class name no other test has used (generated classes are loaded with require).
     */
    private function uniqueName(string $suffix): string
    {
        return 'Generated' . bin2hex(random_bytes(4)) . $suffix;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
