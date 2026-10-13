<?php

declare(strict_types=1);

namespace Kanin\Validation;

/**
 * Thrown when submitted data breaks one or more validation rules.
 *
 * The exception handler turns it into a 422 JSON response shaped like:
 * {"message": "The given data was invalid.", "errors": {"email": ["..."]}}
 */
class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors Field name => list of error messages.
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct($message);
    }

    /**
     * The failed fields and their messages, e.g. ['email' => ['The email field is required.']].
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The HTTP status code to respond with: 422 Unprocessable Content.
     */
    public function getStatusCode(): int
    {
        return 422;
    }
}
