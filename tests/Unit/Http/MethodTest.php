<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\Method;
use PHPUnit\Framework\TestCase;

final class MethodTest extends TestCase
{
    public function test_every_case_is_backed_by_its_uppercase_name(): void
    {
        $values = array_map(fn (Method $method) => $method->value, Method::cases());

        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $values);
        $this->assertSame(Method::PATCH, Method::from('PATCH'));
        $this->assertNull(Method::tryFrom('TRACE'));
    }

    public function test_get_head_and_options_are_read_only(): void
    {
        $this->assertTrue(Method::GET->isReadOnly());
        $this->assertTrue(Method::HEAD->isReadOnly());
        $this->assertTrue(Method::OPTIONS->isReadOnly());
    }

    public function test_writing_methods_are_not_read_only(): void
    {
        $this->assertFalse(Method::POST->isReadOnly());
        $this->assertFalse(Method::PUT->isReadOnly());
        $this->assertFalse(Method::PATCH->isReadOnly());
        $this->assertFalse(Method::DELETE->isReadOnly());
    }
}
