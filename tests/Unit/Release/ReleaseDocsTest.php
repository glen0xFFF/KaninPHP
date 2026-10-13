<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use Kanin\Application;
use PHPUnit\Framework\TestCase;

/**
 * Guards the public release documents: README, CHANGELOG and LICENSE must exist,
 * agree with each other and only show code that matches the real framework.
 */
final class ReleaseDocsTest extends TestCase
{
    /** Every section the public README must contain (spec section 9). */
    private const README_SECTIONS = [
        '## Requirements',
        '## Installation',
        '## Quick Start',
        '## Directory Structure',
        '## Configuration',
        '## Routing',
        '## Controllers',
        '## Requests and Validation',
        '## Responses',
        '## Middleware',
        '### CORS',
        '### Token authentication',
        '### Writing your own middleware',
        '## Database',
        '### Connection',
        '### Query builder',
        '### Pagination',
        '## Models',
        '## Error Handling',
        '## Helpers',
        '## CLI Reference',
        '## Testing',
        '## Deployment',
        '### Apache',
        '### Nginx',
        '## Security Notes',
        '## Contributing',
        '## License',
    ];

    private const CLI_COMMANDS = [
        'php kanin help',
        'php kanin serve',
        'php kanin routes',
        'php kanin make:controller',
        'php kanin make:model',
        'php kanin make:middleware',
        'php kanin db:seed',
    ];

    private const VALIDATION_RULES = [
        'required', 'nullable', 'string', 'email', 'numeric', 'integer', 'min:n', 'max:n',
        'between:a,b', 'confirmed', 'in:a,b,c', 'same:other', 'alpha_num', 'url',
        'unique:table,column', 'exists:table,column',
    ];

    private const HELPERS = ['app', 'config', 'env', 'response', 'route', 'url', 'request', 'db', 'abort', 'dd'];

    /**
     * Read a file from the project root, failing clearly when it is missing.
     */
    private function read(string $file): string
    {
        $path = dirname(__DIR__, 3) . '/' . $file;
        $this->assertFileExists($path, "{$file} is missing from the project root.");

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * Collect the bodies of every ```php fenced block in the README.
     *
     * @return list<string>
     */
    private function readmePhpBlocks(): array
    {
        preg_match_all('/^```php\R(.*?)^```$/ms', $this->read('README.md'), $matches);

        return $matches[1];
    }

    public function test_license_is_mit_with_the_project_copyright(): void
    {
        $license = $this->read('LICENSE');

        $this->assertStringStartsWith('MIT License', $license);
        $this->assertStringContainsString('Copyright (c) 2020-2026 KaninPHP contributors', $license);
        $this->assertStringContainsString('THE SOFTWARE IS PROVIDED "AS IS"', $license);

        $composer = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('MIT', $composer['license']);
    }

    public function test_changelog_has_a_dated_entry_for_the_current_version(): void
    {
        $changelog = $this->read('CHANGELOG.md');

        $this->assertSame('1.0.0', Application::VERSION);
        $this->assertStringContainsString('## [' . Application::VERSION . '] - 2026-10-13', $changelog);
        foreach (['### Added', '### Changed', '### Removed', '### Security'] as $heading) {
            $this->assertStringContainsString($heading, $changelog);
        }
    }

    public function test_readme_has_every_documented_section_in_order(): void
    {
        $readme = $this->read('README.md');
        $previous = -1;

        foreach (self::README_SECTIONS as $heading) {
            $found = preg_match('/^' . preg_quote($heading, '/') . '$/m', $readme, $match, PREG_OFFSET_CAPTURE);
            $this->assertSame(1, $found, "README is missing the heading \"{$heading}\".");
            $this->assertGreaterThan($previous, $match[0][1], "README heading \"{$heading}\" is out of order.");
            $previous = $match[0][1];
        }
    }

    public function test_readme_states_the_current_version_and_welcome_payload(): void
    {
        $readme = $this->read('README.md');

        $this->assertStringStartsWith('# KaninPHP', $readme);
        $this->assertStringContainsString('Current version: **' . Application::VERSION . '**', $readme);
        $this->assertStringContainsString('{"name":"KaninPHP","version":"' . Application::VERSION . '"}', $readme);
    }

    public function test_readme_documents_every_cli_command(): void
    {
        $readme = $this->read('README.md');

        foreach (self::CLI_COMMANDS as $command) {
            $this->assertStringContainsString("`{$command}", $readme, "README does not document {$command}.");
        }
        $this->assertStringContainsString('--api', $readme);
        $this->assertStringContainsString('--host=127.0.0.1', $readme);
        $this->assertStringContainsString('--port=8000', $readme);
    }

    public function test_readme_documents_every_validation_rule(): void
    {
        $readme = $this->read('README.md');

        foreach (self::VALIDATION_RULES as $rule) {
            $this->assertStringContainsString("| `{$rule}` |", $readme, "README does not document the {$rule} rule.");
        }
    }

    public function test_readme_documents_every_helper_and_each_helper_exists(): void
    {
        $readme = $this->read('README.md');

        foreach (self::HELPERS as $helper) {
            $this->assertTrue(function_exists($helper), "Helper {$helper}() does not exist.");
            $this->assertStringContainsString("| `{$helper}(", $readme, "README does not document {$helper}().");
        }
    }

    public function test_readme_documents_every_env_example_key(): void
    {
        $readme = $this->read('README.md');
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $this->read('.env.example'), $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $key) {
            $this->assertStringContainsString("`{$key}`", $readme, "README does not document {$key}.");
        }
    }

    public function test_readme_only_imports_framework_classes_that_exist(): void
    {
        preg_match_all('/^use (Kanin\\\\[A-Za-z\\\\]+);$/m', $this->read('README.md'), $matches);

        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $class) {
            $this->assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "README imports {$class}, which does not exist.",
            );
        }
    }

    public function test_readme_php_examples_have_valid_syntax(): void
    {
        $blocks = $this->readmePhpBlocks();
        $this->assertGreaterThanOrEqual(15, count($blocks), 'README should show plenty of PHP examples.');

        foreach ($blocks as $index => $code) {
            $source = str_starts_with($code, '<?php') ? $code : "<?php\n\n" . $code;
            $file = tempnam(sys_get_temp_dir(), 'kanin-readme-');
            file_put_contents($file, $source);

            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            unlink($file);

            $this->assertSame(0, $exitCode, "README PHP block #{$index} does not compile:\n"
                . implode("\n", $output) . "\n\n" . $code);
        }
    }

    public function test_readme_has_no_leftovers_from_the_old_skeleton(): void
    {
        $readme = $this->read('README.md');

        foreach (['v0.0.1', 'Rice', 'core.php', 'TODO', 'TBD', 'MVC Framework'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $readme);
        }
    }
}
