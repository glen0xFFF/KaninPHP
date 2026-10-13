<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ErrorException;
use Kanin\Application;
use Kanin\Container;
use Kanin\Exceptions\Handler;
use Kanin\Http\HttpException;
use Kanin\Http\MethodNotAllowedHttpException;
use Kanin\Http\NotFoundHttpException;
use Kanin\Http\Request;
use Kanin\Support\Config;
use Kanin\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandlerTest extends TestCase
{
    private string $basePath;
    private Application $app;
    private Handler $handler;
    private Request $request;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kanin-handler-' . bin2hex(random_bytes(6));
        mkdir($this->basePath);

        $this->app = new Application($this->basePath);
        $this->setDebug(false);
        $this->handler = new Handler($this->app);
        $this->request = Request::create('/api/test');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        $this->deleteDirectory($this->basePath);
    }

    private function setDebug(bool $debug): void
    {
        $this->app->instance(Config::class, new Config(['app' => ['debug' => $debug]]));
    }

    private function logPath(): string
    {
        return $this->basePath . '/storage/logs/kanin.log';
    }

    private function deleteDirectory(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->deleteDirectory($path . '/' . $item);
            }
        }

        rmdir($path);
    }

    public function test_validation_exception_renders_422_with_errors(): void
    {
        $errors = ['email' => ['The email field is required.']];

        $response = $this->handler->render($this->request, new ValidationException($errors));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['message' => 'The given data was invalid.', 'errors' => $errors], $response->getData());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
    }

    public function test_validation_exception_keeps_a_custom_message(): void
    {
        $response = $this->handler->render($this->request, new ValidationException(['name' => ['Bad.']], 'Check your input.'));

        $this->assertSame('Check your input.', $response->getData()['message']);
    }

    public function test_not_found_renders_404(): void
    {
        $response = $this->handler->render($this->request, new NotFoundHttpException());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Not Found'], $response->getData());
    }

    public function test_method_not_allowed_renders_405_with_allow_header(): void
    {
        $response = $this->handler->render($this->request, new MethodNotAllowedHttpException(['GET', 'POST']));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['message' => 'Method Not Allowed'], $response->getData());
        $this->assertSame('GET, POST', $response->getHeader('Allow'));
    }

    public function test_http_exception_keeps_status_message_and_headers(): void
    {
        $exception = new HttpException(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer']);

        $response = $this->handler->render($this->request, $exception);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthenticated.'], $response->getData());
        $this->assertSame('Bearer', $response->getHeader('WWW-Authenticate'));
    }

    public function test_http_exception_without_message_uses_the_reason_phrase(): void
    {
        $this->assertSame(['message' => 'Forbidden'], $this->handler->render($this->request, new HttpException(403))->getData());
        $this->assertSame(['message' => 'Too Many Requests'], $this->handler->render($this->request, new HttpException(429))->getData());
        $this->assertSame(['message' => 'Service Unavailable'], $this->handler->render($this->request, new HttpException(503))->getData());
    }

    public function test_unknown_status_without_message_uses_a_generic_phrase(): void
    {
        $this->assertSame(['message' => 'Error'], $this->handler->render($this->request, new HttpException(499))->getData());
        $this->assertSame(['message' => 'Server Error'], $this->handler->render($this->request, new HttpException(599))->getData());
    }

    public function test_http_exceptions_never_include_debug_details(): void
    {
        $this->setDebug(true);

        $response = $this->handler->render($this->request, new NotFoundHttpException());

        $this->assertSame(['message' => 'Not Found'], $response->getData());
    }

    public function test_server_error_in_production_hides_every_detail(): void
    {
        $response = $this->handler->render($this->request, new RuntimeException('database password is hunter2'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Server Error'], $response->getData());
        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertStringNotContainsString(__FILE__, str_replace('\\/', '/', $response->getContent()));
    }

    public function test_server_error_in_debug_mode_shows_details(): void
    {
        $this->setDebug(true);
        $exception = new RuntimeException('Something broke');

        $data = $this->handler->render($this->request, $exception)->getData();

        $this->assertSame('Something broke', $data['message']);
        $this->assertSame(RuntimeException::class, $data['exception']);
        $this->assertSame(__FILE__, $data['file']);
        $this->assertSame($exception->getLine(), $data['line']);
        $this->assertIsList($data['trace']);
        $this->assertContainsOnlyString($data['trace']);
        $this->assertStringStartsWith('#0 ', $data['trace'][0]);
    }

    public function test_debug_output_survives_invalid_utf8_in_the_message(): void
    {
        $this->setDebug(true);

        $response = $this->handler->render($this->request, new RuntimeException("bad byte \xB1 here"));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringStartsWith('bad byte ', $response->getData()['message']);
    }

    public function test_report_writes_server_errors_to_the_log_creating_the_directory(): void
    {
        $exception = new RuntimeException('boom');

        $this->handler->report($exception);

        $this->assertFileExists($this->logPath());
        $log = (string) file_get_contents($this->logPath());
        $expectedLine = sprintf('RuntimeException: boom in %s:%d', __FILE__, $exception->getLine());
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /', $log);
        $this->assertStringContainsString($expectedLine, $log);
        $this->assertStringContainsString('#0 ', $log);
    }

    public function test_report_appends_to_an_existing_log(): void
    {
        $this->handler->report(new RuntimeException('first'));
        $this->handler->report(new RuntimeException('second'));

        $log = (string) file_get_contents($this->logPath());
        $this->assertStringContainsString('RuntimeException: first', $log);
        $this->assertStringContainsString('RuntimeException: second', $log);
    }

    public function test_report_logs_http_exceptions_with_status_500_or_more(): void
    {
        $this->handler->report(new HttpException(503, 'Down for maintenance'));

        $this->assertStringContainsString(
            'Kanin\Http\HttpException: Down for maintenance',
            (string) file_get_contents($this->logPath()),
        );
    }

    public function test_report_skips_client_errors_and_validation_errors(): void
    {
        $this->handler->report(new NotFoundHttpException());
        $this->handler->report(new HttpException(401, 'Unauthenticated.'));
        $this->handler->report(new ValidationException(['name' => ['Required.']]));

        $this->assertFileDoesNotExist($this->logPath());
    }

    public function test_report_never_throws_when_the_log_cannot_be_written(): void
    {
        // "storage" is a file, so storage/logs can never be created.
        file_put_contents($this->basePath . '/storage', 'not a directory');

        $this->handler->report(new RuntimeException('boom'));

        $this->assertFileDoesNotExist($this->logPath());
    }

    /**
     * PHPUnit lowers error_reporting() while a test runs, so these tests set
     * E_ALL themselves and always restore both the level and the handler.
     */
    public function test_registered_error_handler_turns_warnings_into_exceptions(): void
    {
        $previousLevel = error_reporting(E_ALL);
        Handler::registerErrorHandler();

        try {
            trigger_error('Careful!', E_USER_WARNING);
            $this->fail('Expected an ErrorException.');
        } catch (ErrorException $e) {
            $this->assertSame('Careful!', $e->getMessage());
            $this->assertSame(E_USER_WARNING, $e->getSeverity());
            $this->assertSame(__FILE__, $e->getFile());
        } finally {
            restore_error_handler();
            error_reporting($previousLevel);
        }
    }

    public function test_registered_error_handler_ignores_silenced_errors(): void
    {
        $previousLevel = error_reporting(E_ALL);
        Handler::registerErrorHandler();

        try {
            $result = @trigger_error('Quiet please', E_USER_NOTICE);
        } finally {
            restore_error_handler();
            error_reporting($previousLevel);
        }

        $this->assertTrue($result);
        $this->assertSame('Quiet please', error_get_last()['message'] ?? null);
    }

    public function test_registered_error_handler_ignores_levels_outside_error_reporting(): void
    {
        $previousLevel = error_reporting(E_ALL & ~E_USER_DEPRECATED);
        Handler::registerErrorHandler();

        try {
            $result = trigger_error('Old API', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
            error_reporting($previousLevel);
        }

        $this->assertTrue($result);
    }
}
