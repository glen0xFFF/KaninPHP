<?php

declare(strict_types=1);

namespace Kanin\Console;

use Closure;
use Kanin\Application;
use Kanin\Database\Connection;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use RuntimeException;
use Throwable;

/**
 * The "kanin" command line tool.
 *
 * Usage: php kanin <command> [arguments] [--options]
 * Every command returns an exit code: 0 means success, 1 means failure.
 */
class Kanin
{
    /**
     * Generator commands: command => [stub name, target folder].
     */
    private const array GENERATORS = [
        'make:controller' => ['controller', 'app/Controllers'],
        'make:model' => ['model', 'app/Models'],
        'make:middleware' => ['middleware', 'app/Middleware'],
    ];

    /**
     * Where text is written (a stream resource such as STDOUT).
     *
     * @var resource
     */
    private $output;

    /**
     * Create the console.
     *
     * @param Application $app    The application (already booted by the kanin script).
     * @param resource|null $output Stream to write to; defaults to STDOUT.
     */
    public function __construct(private readonly Application $app, $output = null)
    {
        $this->output = $output ?? STDOUT;
    }

    /**
     * Run the command found in $argv and return the exit code.
     *
     * $argv is PHP's own argument list: $argv[0] is the script name
     * ("kanin"), $argv[1] the command, the rest are its arguments.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        [$arguments, $options] = $this->parseArguments(array_slice($argv, 2));

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'serve' => $this->serve($options),
                'routes' => $this->routes(),
                'make:controller', 'make:model', 'make:middleware' => $this->generate($command, $arguments[0] ?? null, $options),
                'db:seed' => $this->seed(),
                default => $this->fail("Unknown command [{$command}]. Run \"php kanin help\" to see every command."),
            };
        } catch (Throwable $e) {
            return $this->fail('Error: ' . $e->getMessage());
        }
    }

    /**
     * Run a shell command, streaming its output, and return its exit code.
     *
     * Kept in its own method so tests can replace it.
     */
    protected function execute(string $command): int
    {
        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Split arguments into plain values and --options.
     *
     * "--port=8080" becomes ['port' => '8080'], "--api" becomes ['api' => true].
     *
     * @param list<string> $raw
     * @return array{0: list<string>, 1: array<string, string|true>}
     */
    private function parseArguments(array $raw): array
    {
        $arguments = [];
        $options = [];

        foreach ($raw as $value) {
            if (! str_starts_with($value, '--')) {
                $arguments[] = $value;
                continue;
            }

            $parts = explode('=', substr($value, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }

        return [$arguments, $options];
    }

    /**
     * Print the list of commands.
     */
    private function help(): int
    {
        $this->line('KaninPHP ' . Application::VERSION);
        $this->line('');
        $this->line('Usage:');
        $this->line('  php kanin <command> [arguments] [--options]');
        $this->line('');
        $this->line('Commands:');
        $this->line('  help                              Show this list');
        $this->line('  serve [--host=127.0.0.1] [--port=8000]');
        $this->line('                                    Start the development server');
        $this->line('  routes                            List every registered route');
        $this->line('  make:controller Name [--api]      Create app/Controllers/Name.php');
        $this->line('  make:model Name                   Create app/Models/Name.php');
        $this->line('  make:middleware Name              Create app/Middleware/Name.php');
        $this->line('  db:seed                           Run database/seed.sql');

        return 0;
    }

    /**
     * Start PHP's built-in web server with public/ as the document root.
     *
     * @param array<string, string|true> $options
     */
    private function serve(array $options): int
    {
        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? '8000';

        if (! is_string($host) || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
            return $this->fail('Invalid host. Use letters, digits, dots and dashes only, e.g. --host=127.0.0.1');
        }

        if (! is_string($port) || preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            return $this->fail('Invalid port. Use a number from 1 to 65535, e.g. --port=8000');
        }

        // Every piece is escaped so nothing typed on the command line can run extra shell commands.
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-S',
            $host . ':' . $port,
            '-t',
            $this->app->basePath('public'),
            $this->app->basePath('public/index.php'),
        ]));

        $this->line("KaninPHP development server: http://{$host}:{$port}");
        $this->line('Press Ctrl+C to stop.');

        return $this->execute($command);
    }

    /**
     * Print every route as a table: METHOD | URI | NAME | ACTION.
     */
    private function routes(): int
    {
        $routes = $this->app->make(Router::class)->routes();

        if ($routes === []) {
            $this->line('No routes registered.');

            return 0;
        }

        $rows = [['METHOD', 'URI', 'NAME', 'ACTION']];

        foreach ($routes as $route) {
            $rows[] = [
                implode('|', $route->methods()),
                $route->uri(),
                $route->getName() ?? '',
                $this->describeAction($route->getAction()),
            ];
        }

        $this->table($rows);

        return 0;
    }

    /**
     * Turn a route action into readable text, e.g. "App\Controllers\UserController@index".
     */
    private function describeAction(Closure|array|string $action): string
    {
        if ($action instanceof Closure) {
            return 'Closure';
        }

        if (is_array($action)) {
            $class = is_object($action[0]) ? $action[0]::class : (string) $action[0];

            return $class . '@' . (string) ($action[1] ?? '');
        }

        return $action;
    }

    /**
     * Print rows as aligned columns; the first row is the header.
     *
     * @param list<list<string>> $rows
     */
    private function table(array $rows): void
    {
        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }

        foreach ($rows as $index => $row) {
            $cells = [];

            foreach ($row as $i => $cell) {
                $cells[] = str_pad($cell, $widths[$i]);
            }

            $this->line(rtrim(implode(' | ', $cells)));

            if ($index === 0) {
                $this->line(implode('-+-', array_map(fn (int $width): string => str_repeat('-', $width), $widths)));
            }
        }
    }

    /**
     * Create a controller, model or middleware class from its stub.
     *
     * @param array<string, string|true> $options
     */
    private function generate(string $command, ?string $name, array $options): int
    {
        if ($name === null) {
            return $this->fail("Missing class name. Example: php kanin {$command} Name");
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            return $this->fail("Invalid name [{$name}]. Use letters and digits only, starting with an uppercase letter (e.g. PostController).");
        }

        [$stub, $folder] = self::GENERATORS[$command];

        if ($command === 'make:controller' && isset($options['api'])) {
            $stub = 'controller.api';
        }

        $relativePath = "{$folder}/{$name}.php";
        $path = $this->app->basePath($relativePath);

        if (file_exists($path)) {
            return $this->fail("{$relativePath} already exists. Nothing was changed.");
        }

        $this->ensureDirectory(dirname($path));
        file_put_contents($path, str_replace('{{ class }}', $name, $this->stub($stub)));
        $this->line("Created {$relativePath}");

        return 0;
    }

    /**
     * Read a template from system/Console/stubs.
     */
    private function stub(string $name): string
    {
        $contents = file_get_contents(__DIR__ . "/stubs/{$name}.stub");

        if ($contents === false) {
            throw new RuntimeException("Stub [{$name}] could not be read.");
        }

        return $contents;
    }

    /**
     * Create the database (for SQLite) and run database/seed.sql.
     */
    private function seed(): int
    {
        $seedFile = $this->app->basePath('database/seed.sql');

        if (! is_file($seedFile)) {
            return $this->fail('Seed file not found: database/seed.sql');
        }

        $config = $this->app->make(Config::class);
        $settings = (array) $config->get('database.connections.' . (string) $config->get('database.default'), []);

        if (($settings['driver'] ?? null) === 'sqlite') {
            $this->createSqliteFile((string) ($settings['database'] ?? ''));
        }

        $this->app->make(Connection::class)->unprepared((string) file_get_contents($seedFile));
        $this->line('Database seeded from database/seed.sql');

        return 0;
    }

    /**
     * Create an empty SQLite database file (and its folder) when it is missing.
     */
    private function createSqliteFile(string $path): void
    {
        if ($path === '' || $path === ':memory:' || file_exists($path)) {
            return;
        }

        $this->ensureDirectory(dirname($path));
        touch($path);
        $this->line("Created SQLite database {$path}");
    }

    /**
     * Create a directory (and its parents) if it does not exist yet.
     */
    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Directory [{$directory}] could not be created.");
        }
    }

    /**
     * Write one line of text.
     */
    private function line(string $text): void
    {
        fwrite($this->output, $text . PHP_EOL);
    }

    /**
     * Write an error message and return the failure exit code (1).
     */
    private function fail(string $message): int
    {
        $this->line($message);

        return 1;
    }
}
