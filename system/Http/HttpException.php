<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * An exception that already knows which HTTP status code to answer with.
 *
 * Throw it anywhere (controller, middleware, model) and the exception
 * handler turns it into a JSON response such as {"message": "..."}.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param int                   $statusCode HTTP status, e.g. 401 or 404.
     * @param string                $message    Text shown to the API client.
     * @param array<string, string> $headers    Extra response headers.
     * @param \Throwable|null       $previous   The original error, if any.
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The HTTP status code the response should use.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Extra headers to add to the response (name => value).
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
