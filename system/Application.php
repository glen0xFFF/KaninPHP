<?php

declare(strict_types=1);

namespace Kanin;

use Kanin\Database\Connection;
use Kanin\Exceptions\Handler;
use Kanin\Http\JsonResponse;
use Kanin\Http\Request;
use Kanin\Http\Response;
use Kanin\Routing\Pipeline;
use Kanin\Routing\Router;
use Kanin\Support\Config;
use Kanin\Support\Env;

/**
 * The heart of a KaninPHP app.
 *
 * It is the service container (so you can ask it for any service) and it
 * knows how to turn an HTTP Request into a Response:
 *
 *     $app = new Application(dirname(__DIR__));
 *     $app->run();   // capture the request, handle it, send the response
 */
class Application extends Container
{
    /** The framework version, shown by GET /api and the CLI. */
    public const VERSION = '1.0.0';

    /** Project root with forward slashes and no trailing slash. */
    private string $basePath;

    /** Has boot() already run? It must only run once. */
    private bool $booted = false;

    /**
     * Create the application for the project folder at $basePath.
     * The new app becomes the global container used by app() and config().
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        static::setInstance($this);
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Build an absolute path inside the project: basePath('config') → '<root>/config'.
     */
    public function basePath(string $path = ''): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    /**
     * Load .env and config, register the core services and the API routes.
     * It runs at most once: calling it again does nothing.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        Env::load($this->basePath('.env'));

        $config = Config::fromDirectory($this->basePath('config'));
        $this->instance(Config::class, $config);

        // Never print PHP errors into responses unless debugging; they still go to the log.
        ini_set('display_errors', $config->get('app.debug', false) ? '1' : '0');
        ini_set('log_errors', '1');

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
        Handler::registerErrorHandler();

        $this->registerServices($config);
        $this->loadRoutes($config);
    }

    /**
     * Turn a Request into a Response. Global middleware (like CORS) wraps
     * the router, and every exception becomes a JSON error response.
     */
    public function handle(Request $request): Response
    {
        try {
            $this->boot();
            $this->instance(Request::class, $request);

            return (new Pipeline($this))
                ->send($request)
                ->through((array) $this->make(Config::class)->get('app.middleware', []))
                ->then(fn (Request $request): Response => $this->dispatchToRouter($request));
        } catch (\Throwable $e) {
            // Reached only when boot() or a global middleware itself throws.
            return $this->renderException($request, $e);
        }
    }

    /**
     * Handle the current HTTP request and send the response to the browser.
     * This is what public/index.php calls.
     */
    public function run(): void
    {
        $this->handle(Request::capture())->send();
    }

    /**
     * Is debug mode on (APP_DEBUG=true)? Never enable it in production:
     * error responses would show file paths and stack traces.
     */
    public function isDebug(): bool
    {
        return $this->has(Config::class) && (bool) $this->make(Config::class)->get('app.debug', false);
    }

    /**
     * Register the shared services every request needs.
     * Each one is created lazily, the first time somebody asks for it.
     */
    private function registerServices(Config $config): void
    {
        $this->singleton(Router::class, fn (): Router => new Router($this));

        $this->singleton(Connection::class, function () use ($config): Connection {
            $name = (string) $config->get('database.default', 'sqlite');
            $settings = $config->get("database.connections.{$name}");

            if (! is_array($settings)) {
                throw new \RuntimeException("Database connection [{$name}] is not configured.");
            }

            return new Connection($this->withProjectRelativeSqlitePath($settings));
        });

        $this->singleton(Handler::class, fn (): Handler => new Handler($this));
    }

    /**
     * A relative SQLite file path means "inside the project", not "inside
     * whatever folder PHP happens to run from" (that is public/ on a web server).
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function withProjectRelativeSqlitePath(array $settings): array
    {
        $database = $settings['database'] ?? null;
        $isAbsolute = is_string($database)
            && (in_array($database[0] ?? '', ['/', chr(92)], true) || preg_match('/^[A-Za-z]:/', $database) === 1);

        if (($settings['driver'] ?? null) === 'sqlite' && is_string($database) && $database !== '' && $database !== ':memory:' && ! $isAbsolute) {
            $settings['database'] = $this->basePath($database);
        }

        return $settings;
    }

    /**
     * Register middleware aliases and load routes/api.php under the /api prefix.
     * Inside routes/api.php the variable $router is the Router.
     */
    private function loadRoutes(Config $config): void
    {
        $router = $this->make(Router::class);

        foreach ((array) $config->get('app.middleware_aliases', []) as $alias => $class) {
            $router->aliasMiddleware((string) $alias, (string) $class);
        }

        $routesFile = $this->basePath('routes/api.php');

        if (is_file($routesFile)) {
            $router->group(['prefix' => 'api'], static function (Router $router) use ($routesFile): void {
                require $routesFile;
            });
        }
    }

    /**
     * The end of the global middleware pipeline: let the router find and run the route.
     * Errors are rendered here so global middleware can still decorate them.
     */
    private function dispatchToRouter(Request $request): Response
    {
        try {
            return $this->make(Router::class)->dispatch($request);
        } catch (\Throwable $e) {
            return $this->renderException($request, $e);
        }
    }

    /**
     * Log the exception (when it matters) and convert it into a JSON response.
     * If even that fails, fall back to a plain 500 that reveals nothing.
     */
    private function renderException(Request $request, \Throwable $e): Response
    {
        try {
            $handler = $this->make(Handler::class);
            $handler->report($e);

            return $handler->render($request, $e);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Server Error'], 500);
        }
    }
}
