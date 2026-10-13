<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * Thrown when the URL exists but not for the HTTP method used (HTTP 405).
 *
 * The response carries an "Allow" header listing the methods that work,
 * for example "Allow: GET, POST".
 */
class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowed The methods this URL accepts, e.g. ['GET', 'POST'].
     */
    public function __construct(array $allowed)
    {
        $methods = array_values(array_unique(array_map('strtoupper', $allowed)));

        parent::__construct(405, 'Method Not Allowed', ['Allow' => implode(', ', $methods)]);
    }
}
