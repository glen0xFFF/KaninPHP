<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use Kanin\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    public function test_it_carries_errors_default_message_and_422_status(): void
    {
        $e = new ValidationException(['email' => ['The email field is required.']]);

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame(['email' => ['The email field is required.']], $e->errors());
        $this->assertSame('The given data was invalid.', $e->getMessage());
        $this->assertSame(422, $e->getStatusCode());
    }

    public function test_the_message_can_be_customised(): void
    {
        $e = new ValidationException([], 'Please fix the form.');

        $this->assertSame('Please fix the form.', $e->getMessage());
        $this->assertSame([], $e->errors());
    }
}
