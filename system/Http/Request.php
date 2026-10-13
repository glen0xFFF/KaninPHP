<?php

declare(strict_types=1);

namespace Kanin\Http;

use Kanin\Validation\Validator;

/**
 * An incoming HTTP request: method, path, query string, body and headers.
 *
 * In production it is built from PHP's superglobals with Request::capture().
 * In tests you build one by hand with Request::create('/api/users', 'POST', [...]).
 */
class Request
{
    /** The HTTP method exactly as the client sent it, uppercased (e.g. "POST"). */
    protected string $realMethod;

    /** @var array<string, string> Headers keyed by lowercase name. */
    protected array $headers = [];

    /** @var array<string, mixed>|null The decoded body, filled in on first use. */
    protected ?array $body = null;

    /** @var array<string, mixed> Values captured from the URL by the router, e.g. ['id' => '5']. */
    protected array $routeParameters = [];

    /**
     * @param string               $method  HTTP method, e.g. "GET".
     * @param string               $uri     Request URI, e.g. "/api/users?page=2".
     * @param array<string, mixed> $query   Query string values ($_GET).
     * @param array<string, mixed> $request Form body values ($_POST).
     * @param array<string, mixed> $server  Server values ($_SERVER).
     * @param array<string, mixed> $headers Headers as name => value (any case).
     * @param string|null          $content The raw body (php://input).
     */
    public function __construct(
        string $method,
        protected string $uri,
        protected array $query = [],
        protected array $request = [],
        protected array $server = [],
        array $headers = [],
        protected ?string $content = null,
    ) {
        $this->realMethod = strtoupper($method);

        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value)
                ? implode(', ', array_map('strval', $value))
                : (string) $value;
        }
    }

    /**
     * Build a Request from PHP's superglobals ($_SERVER, $_GET, $_POST, php://input).
     */
    public static function capture(): static
    {
        $content = file_get_contents('php://input');

        return new static(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            $_POST,
            $_SERVER,
            static::headersFromServer($_SERVER),
            $content === false ? '' : $content,
        );
    }

    /**
     * Build a Request by hand. Handy in tests and scripts.
     *
     *     Request::create('/api/users?page=2');
     *     Request::create('/api/users', 'POST', ['name' => 'Ada']);
     *
     * @param array<string, mixed> $parameters Sent as query for GET/HEAD, as body otherwise.
     * @param array<string, mixed> $headers    Headers as name => value.
     * @param array<string, mixed> $server     Extra or overriding $_SERVER values.
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $parameters = [],
        array $headers = [],
        ?string $content = null,
        array $server = [],
    ): static {
        $method = strtoupper($method);
        $server = array_replace(
            ['HTTP_HOST' => 'localhost', 'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            $server,
        );

        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $query = $isRead ? array_replace($query, $parameters) : $query;
        $body = $isRead ? [] : $parameters;
        $headers = array_replace(static::headersFromServer($server), array_change_key_case($headers));

        return new static($method, $uri, $query, $body, $server, $headers, $content);
    }

    /**
     * The HTTP method as a Method enum.
     *
     * HTML forms can only send GET and POST, so a POST may ask to be treated
     * as PUT, PATCH or DELETE with an "_method" input or an
     * "X-HTTP-Method-Override" header.
     *
     * @throws HttpException 405 when the client used a method KaninPHP does not know.
     */
    public function method(): Method
    {
        $method = Method::tryFrom($this->realMethod)
            ?? throw new HttpException(405, 'Method Not Allowed');

        if ($method !== Method::POST) {
            return $method;
        }

        $override = $this->input('_method') ?? $this->header('X-HTTP-Method-Override');
        $override = is_string($override) ? strtoupper(trim($override)) : '';

        return match ($override) {
            'PUT' => Method::PUT,
            'PATCH' => Method::PATCH,
            'DELETE' => Method::DELETE,
            default => Method::POST,
        };
    }

    /**
     * The decoded URL path without the query string: "/api/users", or "/" for the root.
     */
    public function path(): string
    {
        $path = substr($this->uri, 0, strcspn($this->uri, '?#'));

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $path) === 1) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        return '/' . trim(rawurldecode($path), '/');
    }

    /**
     * Read one input value, or all input when $key is null.
     *
     * Body values win over query string values with the same name.
     *
     * @throws HttpException 400 when the body claims to be JSON but is not valid JSON.
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $input = array_replace($this->query, $this->body());

        if ($key === null) {
            return $input;
        }

        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    /**
     * Every input value (query string plus body).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->input();
    }

    /**
     * Only the listed input keys that are actually present.
     *
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Every input value except the listed keys.
     *
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Tell whether the input contains $key (even if its value is null or "").
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Read one query string value, or the whole query string when $key is null.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * Read a header (the name is case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * The token from an "Authorization: Bearer <token>" header, or null.
     */
    public function bearerToken(): ?string
    {
        $header = trim((string) $this->header('Authorization', ''));

        if (preg_match('/^Bearer\s+(.*)$/is', $header, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    /**
     * Tell whether the body is JSON (Content-Type like application/json or application/vnd.api+json).
     */
    public function isJson(): bool
    {
        $type = strtolower((string) $this->header('Content-Type', ''));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    /**
     * Tell whether the request came in over HTTPS.
     */
    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    /**
     * The host name: the Host header, else SERVER_NAME, else "localhost".
     */
    public function host(): string
    {
        $host = $this->header('Host') ?? (string) ($this->server['SERVER_NAME'] ?? '');

        return $host === '' ? 'localhost' : $host;
    }

    /**
     * Store the values the router captured from the URL (e.g. {id}).
     *
     * @param array<string, mixed> $parameters
     */
    public function setRouteParameters(array $parameters): void
    {
        $this->routeParameters = $parameters;
    }

    /**
     * Read one route parameter, or all of them when $key is null.
     */
    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->routeParameters;
        }

        return array_key_exists($key, $this->routeParameters) ? $this->routeParameters[$key] : $default;
    }

    /**
     * The request body as an array, decoding JSON the first time it is needed.
     *
     * @return array<string, mixed>
     *
     * @throws HttpException 400 when a JSON body is malformed or is not an object/array.
     */
    protected function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }

        if (! $this->isJson() || $this->content === null || trim($this->content) === '') {
            return $this->body = $this->request;
        }

        try {
            $decoded = json_decode($this->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpException(400, 'Malformed JSON body.', [], $e);
        }

        if (! is_array($decoded)) {
            throw new HttpException(400, 'Malformed JSON body.');
        }

        return $this->body = array_replace($this->request, $decoded);
    }

    /**
     * Turn $_SERVER entries (HTTP_ACCEPT, CONTENT_TYPE, ...) into lowercase header names.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    protected static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Validate this request's input (query + body) against the given rules.
     *
     * Returns only the validated fields. When validation fails a
     * ValidationException is thrown, which KaninPHP turns into a 422 JSON
     * response for you - so your controller code only runs with good data.
     *
     *     $data = $request->validate(['email' => 'required|email']);
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = []): array
    {
        return Validator::make($this->all(), $rules, $messages)->validate();
    }
}
