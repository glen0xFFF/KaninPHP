<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * An HTTP response: a status code, some headers and a body.
 *
 * Build one, adjust it as needed, then call send() to deliver it to the
 * browser or API client.
 */
class Response
{
    /** The status code, e.g. 200 (OK) or 404 (Not Found). */
    protected int $statusCode = 200;

    /**
     * Headers keyed by lowercase name, so lookups ignore case.
     * Each entry keeps the original spelling: ['content-type' => ['Content-Type', 'text/plain']].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $headers = [];

    /**
     * @param string                $content The response body.
     * @param int                   $status  The HTTP status code (100-599).
     * @param array<string, string> $headers Headers as name => value.
     */
    public function __construct(protected string $content = '', int $status = 200, array $headers = [])
    {
        $this->setStatusCode($status);

        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        if ($this->getHeader('Content-Type') === null) {
            $this->header('Content-Type', 'text/plain; charset=UTF-8');
        }
    }

    /**
     * Get the response body.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Replace the response body.
     */
    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Change the HTTP status code. Only 100-599 are valid HTTP statuses.
     *
     * @throws \InvalidArgumentException When the code is outside 100-599.
     */
    public function setStatusCode(int $status): static
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException("Invalid HTTP status code [{$status}].");
        }

        $this->statusCode = $status;

        return $this;
    }

    /**
     * Set a header, replacing any existing header with the same name (any case).
     *
     * Line breaks are refused because they would let an attacker inject
     * extra headers into the response ("header injection").
     *
     * @throws \InvalidArgumentException When the name is invalid or the value contains a line break.
     */
    public function header(string $name, string $value): static
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid header name [{$name}].");
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException("Header [{$name}] contains a line break.");
        }

        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /**
     * Read one header value (case-insensitive), or null when it is not set.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    /**
     * Get every header as name => value, using the spelling they were set with.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Send the status code, headers and body to the client.
     */
    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers() as $name => $value) {
                header("{$name}: {$value}", true);
            }
        }

        echo $this->content;
    }
}
