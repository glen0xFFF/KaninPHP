<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Kanin\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            array_map('unlink', glob($this->directory . '/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function test_get_reads_top_level_and_nested_values_with_dot_notation(): void
    {
        $config = new Config(['app' => ['name' => 'KaninPHP', 'db' => ['driver' => 'sqlite']]]);

        $this->assertSame('KaninPHP', $config->get('app.name'));
        $this->assertSame('sqlite', $config->get('app.db.driver'));
        $this->assertSame(['driver' => 'sqlite'], $config->get('app.db'));
    }

    public function test_get_returns_the_default_for_missing_keys(): void
    {
        $config = new Config(['app' => ['name' => 'KaninPHP']]);

        $this->assertSame('fallback', $config->get('app.missing', 'fallback'));
        $this->assertSame('fallback', $config->get('missing.deeper', 'fallback'));
        $this->assertNull($config->get('app.name.too.deep'));
    }

    public function test_get_returns_a_stored_null_instead_of_the_default(): void
    {
        $config = new Config(['app' => ['key' => null]]);

        $this->assertNull($config->get('app.key', 'fallback'));
    }

    public function test_set_writes_values_and_creates_nested_arrays(): void
    {
        $config = new Config();

        $config->set('cache.stores.file.path', '/tmp');
        $config->set('app.name', 'Kanin');

        $this->assertSame('/tmp', $config->get('cache.stores.file.path'));
        $this->assertSame(['cache' => ['stores' => ['file' => ['path' => '/tmp']]], 'app' => ['name' => 'Kanin']], $config->all());
    }

    public function test_set_overwrites_existing_values_and_keeps_siblings(): void
    {
        $config = new Config(['app' => ['name' => 'Old', 'debug' => false]]);

        $config->set('app.name', 'New');

        $this->assertSame(['app' => ['name' => 'New', 'debug' => false]], $config->all());
    }

    public function test_set_replaces_a_scalar_that_is_in_the_way(): void
    {
        $config = new Config(['app' => 'scalar']);

        $config->set('app.name', 'Kanin');

        $this->assertSame('Kanin', $config->get('app.name'));
    }

    public function test_has_checks_keys_including_null_values(): void
    {
        $config = new Config(['app' => ['name' => 'Kanin', 'key' => null]]);

        $this->assertTrue($config->has('app'));
        $this->assertTrue($config->has('app.name'));
        $this->assertTrue($config->has('app.key'));
        $this->assertFalse($config->has('app.missing'));
        $this->assertFalse($config->has('missing'));
    }

    public function test_all_returns_every_item(): void
    {
        $items = ['app' => ['name' => 'Kanin'], 'cors' => ['max_age' => 600]];

        $this->assertSame($items, (new Config($items))->all());
    }

    public function test_from_directory_loads_each_php_file_keyed_by_file_name(): void
    {
        $this->makeDirectory([
            'app.php' => "<?php\n\nreturn ['name' => 'Kanin', 'debug' => true];\n",
            'database.php' => "<?php\n\nreturn ['default' => 'sqlite'];\n",
            'notes.txt' => 'not a config file',
        ]);

        $config = Config::fromDirectory($this->directory);

        $this->assertSame(
            ['app' => ['name' => 'Kanin', 'debug' => true], 'database' => ['default' => 'sqlite']],
            $config->all(),
        );
        $this->assertSame('sqlite', $config->get('database.default'));
    }

    public function test_from_directory_accepts_a_trailing_slash(): void
    {
        $this->makeDirectory(['app.php' => "<?php\n\nreturn ['name' => 'Kanin'];\n"]);

        $this->assertSame('Kanin', Config::fromDirectory($this->directory . '/')->get('app.name'));
    }

    public function test_from_directory_returns_empty_config_for_a_missing_directory(): void
    {
        $config = Config::fromDirectory(sys_get_temp_dir() . '/kanin-missing-' . bin2hex(random_bytes(4)));

        $this->assertSame([], $config->all());
    }

    public function test_from_directory_rejects_a_file_that_does_not_return_an_array(): void
    {
        $this->makeDirectory(['broken.php' => "<?php\n\nreturn 'oops';\n"]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Config file [broken.php] must return an array.');

        Config::fromDirectory($this->directory);
    }

    /**
     * @param array<string, string> $files file name => contents
     */
    private function makeDirectory(array $files): void
    {
        $this->directory = sys_get_temp_dir() . '/kanin-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory);

        foreach ($files as $name => $contents) {
            file_put_contents($this->directory . '/' . $name, $contents);
        }
    }
}
