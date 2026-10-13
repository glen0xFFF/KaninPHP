<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Kanin\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    /** @var list<string> Environment keys this test touched, cleaned in tearDown(). */
    private array $keys = [];

    /** @var list<string> Temporary files to delete in tearDown(). */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function test_parse_reads_key_value_pairs_as_strings(): void
    {
        $this->assertSame(
            ['APP_NAME' => 'KaninPHP', 'APP_DEBUG' => 'true', 'PORT' => '8000'],
            Env::parse("APP_NAME=KaninPHP\nAPP_DEBUG=true\nPORT=8000"),
        );
    }

    public function test_parse_ignores_blank_lines_and_comment_lines(): void
    {
        $contents = "# database settings\n\n   \nDB_CONNECTION=sqlite\n  # indented comment\n";

        $this->assertSame(['DB_CONNECTION' => 'sqlite'], Env::parse($contents));
    }

    public function test_parse_handles_windows_line_endings_and_spaces_around_equals(): void
    {
        $this->assertSame(['A' => '1', 'B' => '2'], Env::parse("A = 1\r\nB=2\r\n"));
    }

    public function test_parse_strips_matching_double_and_single_quotes(): void
    {
        $values = Env::parse("NAME=\"My API\"\nGREETING='hello world'\nEMPTY=\"\"");

        $this->assertSame(['NAME' => 'My API', 'GREETING' => 'hello world', 'EMPTY' => ''], $values);
    }

    public function test_parse_keeps_hash_signs_inside_quotes(): void
    {
        $this->assertSame(['COLOR' => '#ff0000'], Env::parse('COLOR="#ff0000" # brand colour'));
    }

    public function test_parse_keeps_mismatched_quotes_as_they_are(): void
    {
        $this->assertSame(['ODD' => '"half'], Env::parse('ODD="half'));
    }

    public function test_parse_removes_trailing_comments_from_unquoted_values(): void
    {
        $this->assertSame(
            ['DEBUG' => 'true', 'URL' => 'http://localhost#anchor'],
            Env::parse("DEBUG=true # turn off in production\nURL=http://localhost#anchor"),
        );
    }

    public function test_parse_keeps_equals_signs_inside_values(): void
    {
        $this->assertSame(['TOKEN' => 'abc=def=='], Env::parse('TOKEN=abc=def=='));
    }

    public function test_parse_skips_lines_without_equals_or_with_invalid_keys(): void
    {
        $this->assertSame(['GOOD' => 'yes'], Env::parse("just text\n1BAD=no\nBAD KEY=no\nGOOD=yes"));
    }

    public function test_parse_lets_later_lines_override_earlier_ones(): void
    {
        $this->assertSame(['A' => '2'], Env::parse("A=1\nA=2"));
    }

    public function test_load_sets_env_server_and_getenv(): void
    {
        $this->track('KANIN_TEST_LOAD');

        Env::load($this->envFile("KANIN_TEST_LOAD=loaded\n"));

        $this->assertSame('loaded', $_ENV['KANIN_TEST_LOAD']);
        $this->assertSame('loaded', $_SERVER['KANIN_TEST_LOAD']);
        $this->assertSame('loaded', getenv('KANIN_TEST_LOAD'));
    }

    public function test_load_does_not_override_a_real_environment_variable(): void
    {
        $this->track('KANIN_TEST_REAL');
        putenv('KANIN_TEST_REAL=from-shell');

        Env::load($this->envFile("KANIN_TEST_REAL=from-file\n"));

        $this->assertSame('from-shell', getenv('KANIN_TEST_REAL'));
        $this->assertArrayNotHasKey('KANIN_TEST_REAL', $_ENV);
    }

    public function test_load_does_not_override_an_existing_env_array_value(): void
    {
        $this->track('KANIN_TEST_ARRAY');
        $_ENV['KANIN_TEST_ARRAY'] = 'from-env-array';

        Env::load($this->envFile("KANIN_TEST_ARRAY=from-file\n"));

        $this->assertSame('from-env-array', $_ENV['KANIN_TEST_ARRAY']);
        $this->assertFalse(getenv('KANIN_TEST_ARRAY'));
    }

    public function test_load_does_nothing_when_the_file_is_missing(): void
    {
        $this->track('KANIN_TEST_MISSING');

        Env::load(sys_get_temp_dir() . '/kanin-does-not-exist-' . bin2hex(random_bytes(4)) . '.env');

        $this->assertNull(Env::get('KANIN_TEST_MISSING'));
    }

    public function test_get_returns_the_default_when_the_key_is_missing(): void
    {
        $this->assertSame('fallback', Env::get('KANIN_TEST_NOT_SET', 'fallback'));
        $this->assertNull(Env::get('KANIN_TEST_NOT_SET'));
    }

    public function test_get_reads_env_then_server_then_getenv(): void
    {
        $this->track('KANIN_TEST_ORDER');
        putenv('KANIN_TEST_ORDER=from-getenv');
        $this->assertSame('from-getenv', Env::get('KANIN_TEST_ORDER'));

        $_SERVER['KANIN_TEST_ORDER'] = 'from-server';
        $this->assertSame('from-server', Env::get('KANIN_TEST_ORDER'));

        $_ENV['KANIN_TEST_ORDER'] = 'from-env';
        $this->assertSame('from-env', Env::get('KANIN_TEST_ORDER'));
    }

    public function test_get_casts_special_words(): void
    {
        $cases = [
            'true' => true, '(true)' => true, 'TRUE' => true,
            'false' => false, '(false)' => false,
            'null' => null, '(null)' => null,
            'empty' => '', '(empty)' => '',
            'hello' => 'hello', '0' => '0',
        ];

        foreach ($cases as $raw => $expected) {
            $this->track('KANIN_TEST_CAST');
            $_ENV['KANIN_TEST_CAST'] = (string) $raw;

            $this->assertSame($expected, Env::get('KANIN_TEST_CAST', 'default'), "Casting [{$raw}]");
        }
    }

    public function test_get_returns_null_not_the_default_for_an_explicit_null(): void
    {
        $this->track('KANIN_TEST_NULL');
        $_ENV['KANIN_TEST_NULL'] = 'null';

        $this->assertNull(Env::get('KANIN_TEST_NULL', 'default'));
    }

    private function track(string $key): void
    {
        $this->keys[] = $key;
    }

    private function envFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kanin-env-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
