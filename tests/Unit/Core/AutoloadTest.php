<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function test_framework_namespace_maps_to_the_system_directory(): void
    {
        $this->assertSame($this->rootPath('system'), $this->psr4Path('Kanin\\'));
    }

    public function test_application_namespace_maps_to_the_app_directory(): void
    {
        $this->assertSame($this->rootPath('app'), $this->psr4Path('App\\'));
    }

    public function test_tests_namespace_maps_to_the_tests_directory(): void
    {
        $this->assertSame($this->rootPath('tests'), $this->psr4Path('Tests\\'));
    }

    public function test_project_runs_on_php_8_5_or_newer(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '8.5.0', '>='));
    }

    /**
     * Ask Composer's class loader which directory a PSR-4 prefix points to.
     */
    private function psr4Path(string $prefix): string
    {
        $paths = $this->loader()->getPrefixesPsr4()[$prefix] ?? [];
        $this->assertCount(1, $paths, "Prefix [{$prefix}] should map to exactly one directory.");

        return $this->normalize((string) realpath($paths[0]) ?: $paths[0]);
    }

    private function loader(): ClassLoader
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $this->assertNotEmpty($loaders, 'The Composer autoloader is not registered.');

        return reset($loaders);
    }

    private function rootPath(string $directory): string
    {
        $path = dirname(__DIR__, 3) . '/' . $directory;

        return $this->normalize((string) realpath($path) ?: $path);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
