<?php

declare(strict_types=1);

namespace Kanin\Support;

/**
 * Reads `.env` files and environment variables.
 *
 * A `.env` file holds settings that change between machines (database
 * password, debug mode, API tokens) so they never have to be hard-coded:
 *
 *     APP_DEBUG=true
 *     APP_NAME="My API"   # quotes are optional
 */
final class Env
{
    /**
     * Turn the text of a `.env` file into a [KEY => value] array.
     *
     * Blank lines, `# comment` lines, lines without `=` and invalid key names
     * are skipped. Values always stay strings; get() does the casting.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) !== 1) {
                continue;
            }

            $values[$key] = self::parseValue(trim($value));
        }

        return $values;
    }

    /**
     * Load a `.env` file into the environment.
     *
     * Variables that already exist (set by the server, the shell or
     * phpunit.xml) are NEVER overwritten, so real environment wins.
     */
    public static function load(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            return;
        }

        foreach (self::parse((string) file_get_contents($path)) as $key => $value) {
            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * Read an environment variable, or $default when it is not set.
     *
     * The strings true/false/null/empty (also written as "(true)" etc.)
     * are converted to true, false, null and '' respectively.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);

            if ($value === false) {
                return $default;
            }
        }

        return is_string($value) ? self::cast($value) : $value;
    }

    /**
     * Remove surrounding quotes, or a trailing comment from unquoted values.
     */
    private static function parseValue(string $value): string
    {
        if (preg_match('/^(["\'])(.*)\1\s*(#.*)?$/', $value, $matches) === 1) {
            return $matches[2];
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /**
     * Convert the special words true/false/null/empty to real PHP values.
     */
    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
