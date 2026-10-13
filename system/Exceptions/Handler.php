<?php

declare(strict_types=1);

namespace Kanin\Exceptions;

use ErrorException;
use Kanin\Application;
use Kanin\Http\HttpException;
use Kanin\Http\JsonResponse;
use Kanin\Http\Request;
use Kanin\Validation\ValidationException;
use Throwable;

/**
 * Turns every error into a JSON response and writes server errors to a log file.
 *
 * - ValidationException → 422 {"message": ..., "errors": {...}}
 * - HttpException       → its own status, e.g. 404 {"message": "Not Found"}
 * - anything else       → 500 {"message": "Server Error"}
 *
 * With APP_DEBUG=true a 500 also shows the real message, file, line and trace.
 * Never enable debug in production: it reveals your code to strangers.
 */
class Handler
{
    /** Standard reason phrases, used when an HttpException has no message. */
    private const REASON_PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * Create the handler.
     *
     * @param Application $app Used for the log path and the debug flag.
     */
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Write a server error to storage/logs/kanin.log.
     *
     * Client errors (4xx HttpException, ValidationException) are expected and
     * are not logged. This method never throws: a broken log file must not
     * hide the original error from the user.
     */
    public function report(Throwable $e): void
    {
        if (! $this->shouldReport($e)) {
            return;
        }

        try {
            $this->writeLog($this->formatLogEntry($e));
        } catch (Throwable) {
            // Logging failed (disk full, no permission...). Nothing more we can do.
        }
    }

    /**
     * Convert any exception into a JSON response.
     *
     * @param Request   $request The request that failed (available to subclasses).
     * @param Throwable $e       The error to render.
     */
    public function render(Request $request, Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => new JsonResponse(
                ['message' => $e->getMessage(), 'errors' => $e->errors()],
                $e->getStatusCode(),
            ),
            $e instanceof HttpException => $this->renderHttpException($e),
            default => $this->renderServerError($e),
        };
    }

    /**
     * Make PHP warnings and notices throw an ErrorException instead of
     * printing text into your JSON. Errors silenced with @ are left alone.
     */
    public static function registerErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private function shouldReport(Throwable $e): bool
    {
        if ($e instanceof ValidationException) {
            return false;
        }

        return ! ($e instanceof HttpException && $e->getStatusCode() < 500);
    }

    private function renderHttpException(HttpException $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $message = $e->getMessage() !== '' ? $e->getMessage() : $this->reasonPhrase($status);

        return new JsonResponse(['message' => $this->clean($message)], $status, $e->getHeaders());
    }

    private function renderServerError(Throwable $e): JsonResponse
    {
        if (! $this->app->isDebug()) {
            return new JsonResponse(['message' => 'Server Error'], 500);
        }

        return new JsonResponse([
            'message' => $this->clean($e->getMessage()),
            'exception' => $e::class,
            'file' => $this->clean($e->getFile()),
            'line' => $e->getLine(),
            'trace' => array_map($this->clean(...), explode("\n", $e->getTraceAsString())),
        ], 500);
    }

    private function reasonPhrase(int $status): string
    {
        return self::REASON_PHRASES[$status] ?? ($status >= 500 ? 'Server Error' : 'Error');
    }

    /**
     * Replace invalid UTF-8 bytes so json_encode() can never fail on a message.
     */
    private function clean(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }

    private function formatLogEntry(Throwable $e): string
    {
        return sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
    }

    private function writeLog(string $entry): void
    {
        $path = $this->app->basePath('storage/logs/kanin.log');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }
}
