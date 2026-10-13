<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * Thrown when nothing exists at the requested address (HTTP 404).
 */
class NotFoundHttpException extends HttpException
{
    /**
     * Create a 404 exception with an optional custom message.
     */
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct(404, $message);
    }
}
