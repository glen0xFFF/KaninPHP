<?php

declare(strict_types=1);

namespace Kanin\Support;

/**
 * Holds every configuration value and reads them with "dot notation".
 *
 * The file config/database.php becomes the key "database", so
 * get('database.connections.sqlite.driver') walks into nested arrays.
 */
class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Load every `*.php` file in a directory; each must return an array.
     *
     * A missing directory simply gives an empty Config.
     */
    public static function fromDirectory(string $path): static
    {
        $items = [];

        foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
            $values = (static fn (string $__file): mixed => require $__file)($file);

            if (! is_array($values)) {
                throw new \UnexpectedValueException(
                    sprintf('Config file [%s] must return an array.', basename($file))
                );
            }

            $items[basename($file, '.php')] = $values;
        }

        return new static($items);
    }

    /**
     * Read a value, e.g. get('app.name') or get('app.missing', 'fallback').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Write a value, creating nested arrays on the way: set('a.b.c', 1).
     */
    public function set(string $key, mixed $value): void
    {
        $target = &$this->items;

        foreach (explode('.', $key) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    /**
     * Check whether a key exists (even when its value is null).
     */
    public function has(string $key): bool
    {
        $marker = new \stdClass();

        return $this->get($key, $marker) !== $marker;
    }

    /**
     * Get every configuration value as one nested array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
