<?php

declare(strict_types=1);

namespace Kanin\Http;

/**
 * The HTTP methods (verbs) KaninPHP understands.
 *
 * Using an enum instead of plain strings means a typo such as "GTE"
 * cannot sneak through: PHP only accepts the cases listed here.
 */
enum Method: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';

    /**
     * Tell whether this method only reads data and never changes it.
     *
     * GET, HEAD and OPTIONS are "safe" methods; the rest may modify data.
     */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS => true,
            default => false,
        };
    }
}
