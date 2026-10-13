<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * A response whose body is JSON. This is what a KaninPHP API returns most.
 *
 *     return new JsonResponse(['name' => 'Ada'], 201);
 */
class JsonResponse extends Response
{
    /** Flags used for every encode: throw on failure, keep "/" and unicode readable,
     *  replace broken UTF-8 with U+FFFD. */
    public const ENCODING_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param mixed                 $data    Anything json_encode() accepts (arrays, models, ...).
     * @param int                   $status  The HTTP status code. 204 always has an empty body.
     * @param array<string, string> $headers Extra headers as name => value. A Content-Type
     *                                       given here replaces the default application/json.
     *
     * @throws \JsonException When $data cannot be encoded (e.g. INF or NAN).
     */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        $content = $status === 204 ? '' : json_encode($data, self::ENCODING_FLAGS);

        parent::__construct($content, $status, array_merge(['Content-Type' => 'application/json'], $headers));
    }

    /**
     * Decode the JSON body back into PHP data (objects become arrays).
     *
     * An empty body (e.g. a 204 response) gives null.
     *
     * @throws \JsonException When the body is not valid JSON.
     */
    public function getData(): mixed
    {
        if ($this->content === '') {
            return null;
        }

        return json_decode($this->content, true, 512, JSON_THROW_ON_ERROR);
    }
}
