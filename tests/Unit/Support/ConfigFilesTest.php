<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Kanin\Middleware\AuthenticateToken;
use Kanin\Middleware\HandleCors;
use PHPUnit\Framework\TestCase;

final class ConfigFilesTest extends TestCase
{
    /**
     * Require config/<name>.php with the given environment variables.
     * A null value means "not set at all". The real environment is restored afterwards.
     */
    private function loadConfig(string $name, array $env = []): array
    {
        $backup = [];

        foreach ($env as $key => $value) {
            $backup[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if ($value !== null) {
                $_ENV[$key] = $value;
            }
        }

        try {
            return require dirname(__DIR__, 3) . "/config/{$name}.php";
        } finally {
            foreach ($backup as $key => [$envValue, $serverValue, $processValue]) {
                unset($_ENV[$key], $_SERVER[$key]);

                if ($envValue !== null) {
                    $_ENV[$key] = $envValue;
                }

                if ($serverValue !== null) {
                    $_SERVER[$key] = $serverValue;
                }

                putenv($processValue === false ? $key : "{$key}={$processValue}");
            }
        }
    }

    public function test_app_config_defaults(): void
    {
        $config = $this->loadConfig('app', [
            'APP_NAME' => null, 'APP_ENV' => null, 'APP_DEBUG' => null, 'APP_URL' => null, 'APP_TIMEZONE' => null,
        ]);

        $this->assertSame([
            'name' => 'KaninPHP',
            'env' => 'production',
            'debug' => false,
            'url' => 'http://localhost:8000',
            'timezone' => 'UTC',
            'middleware' => [HandleCors::class],
            'middleware_aliases' => ['auth.token' => AuthenticateToken::class],
        ], $config);
    }

    public function test_app_config_reads_the_environment(): void
    {
        $config = $this->loadConfig('app', [
            'APP_NAME' => 'My API', 'APP_ENV' => 'local', 'APP_DEBUG' => 'true',
            'APP_URL' => 'https://api.example.com', 'APP_TIMEZONE' => 'Asia/Manila',
        ]);

        $this->assertSame('My API', $config['name']);
        $this->assertSame('local', $config['env']);
        $this->assertTrue($config['debug']);
        $this->assertSame('https://api.example.com', $config['url']);
        $this->assertSame('Asia/Manila', $config['timezone']);
    }

    public function test_app_debug_false_string_is_false(): void
    {
        $this->assertFalse($this->loadConfig('app', ['APP_DEBUG' => 'false'])['debug']);
    }

    public function test_database_config_defaults_to_the_sqlite_file(): void
    {
        $config = $this->loadConfig('database', ['DB_CONNECTION' => null, 'DB_DATABASE' => null]);

        $this->assertSame('sqlite', $config['default']);
        $this->assertSame([
            'driver' => 'sqlite',
            'database' => dirname(__DIR__, 3) . '/database/database.sqlite',
        ], $config['connections']['sqlite']);
    }

    public function test_database_config_reads_the_environment(): void
    {
        $config = $this->loadConfig('database', ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']);

        $this->assertSame(':memory:', $config['connections']['sqlite']['database']);
    }

    public function test_database_config_server_defaults(): void
    {
        $config = $this->loadConfig('database', [
            'DB_HOST' => null, 'DB_PORT' => null, 'DB_DATABASE' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null,
        ]);

        $this->assertSame([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'kanin',
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
        ], $config['connections']['mysql']);
        $this->assertSame(5432, $config['connections']['pgsql']['port']);
        $this->assertSame('postgres', $config['connections']['pgsql']['username']);
        $this->assertSame('pgsql', $config['connections']['pgsql']['driver']);
    }

    public function test_cors_config_defaults(): void
    {
        $this->assertSame([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
            'exposed_headers' => [],
            'max_age' => 600,
            'supports_credentials' => false,
        ], $this->loadConfig('cors', ['CORS_ALLOWED_ORIGINS' => null]));
    }

    public function test_cors_origins_are_comma_separated_and_trimmed(): void
    {
        $config = $this->loadConfig('cors', ['CORS_ALLOWED_ORIGINS' => ' https://a.test , ,https://b.test ']);

        $this->assertSame(['https://a.test', 'https://b.test'], $config['allowed_origins']);
    }

    public function test_auth_tokens_are_comma_separated_trimmed_and_non_empty(): void
    {
        $this->assertSame(['abc', 'def'], $this->loadConfig('auth', ['API_TOKENS' => ' abc , ,def, '])['tokens']);
    }

    public function test_auth_tokens_default_to_an_empty_list_which_denies_everything(): void
    {
        $this->assertSame([], $this->loadConfig('auth', ['API_TOKENS' => null])['tokens']);
        $this->assertSame([], $this->loadConfig('auth', ['API_TOKENS' => ''])['tokens']);
    }
}
