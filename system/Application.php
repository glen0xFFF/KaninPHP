<?php

declare(strict_types=1);

namespace Kanin;

use Kanin\Support\Config;

/**
 * The application: the service container plus knowledge of where the project lives.
 *
 * This first version only holds the pieces the exception handler needs
 * (base path and debug flag). Task 11 replaces this file with the full
 * Application (boot, handle, run).
 */
class Application extends Container
{
    public const VERSION = '1.0.0';

    private string $basePath;

    /**
     * Create the application for the project rooted at $basePath.
     *
     * @param string $basePath Absolute path of the project root folder.
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        static::setInstance($this);
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Build an absolute path inside the project, e.g. basePath('config').
     *
     * @param string $path A path relative to the project root ('' for the root itself).
     */
    public function basePath(string $path = ''): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return $path === '' ? $this->basePath : $this->basePath . '/' . $path;
    }

    /**
     * Whether detailed error output is enabled (config "app.debug").
     */
    public function isDebug(): bool
    {
        return (bool) $this->make(Config::class)->get('app.debug', false);
    }
}
