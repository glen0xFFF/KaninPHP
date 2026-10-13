<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\HttpException;
use Kanin\Http\MethodNotAllowedHttpException;
use Kanin\Http\NotFoundHttpException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function test_http_exception_carries_status_message_headers_and_previous(): void
    {
        $previous = new \LogicException('root cause');
        $e = new HttpException(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer'], $previous);

        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Unauthenticated.', $e->getMessage());
        $this->assertSame(['WWW-Authenticate' => 'Bearer'], $e->getHeaders());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_http_exception_defaults_to_empty_message_and_no_headers(): void
    {
        $e = new HttpException(503);

        $this->assertSame(503, $e->getStatusCode());
        $this->assertSame('', $e->getMessage());
        $this->assertSame([], $e->getHeaders());
        $this->assertNull($e->getPrevious());
    }

    public function test_http_exception_can_be_thrown_and_caught(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Teapot');

        throw new HttpException(418, 'Teapot');
    }

    public function test_not_found_is_a_404_with_default_message(): void
    {
        $e = new NotFoundHttpException();

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('Not Found', $e->getMessage());
        $this->assertSame([], $e->getHeaders());
    }

    public function test_not_found_accepts_a_custom_message(): void
    {
        $e = new NotFoundHttpException('User not found.');

        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('User not found.', $e->getMessage());
    }

    public function test_method_not_allowed_is_a_405_with_allow_header(): void
    {
        $e = new MethodNotAllowedHttpException(['GET', 'POST']);

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(405, $e->getStatusCode());
        $this->assertSame('Method Not Allowed', $e->getMessage());
        $this->assertSame(['Allow' => 'GET, POST'], $e->getHeaders());
    }

    public function test_method_not_allowed_uppercases_and_deduplicates_methods(): void
    {
        $e = new MethodNotAllowedHttpException(['get', 'HEAD', 'GET', 'delete']);

        $this->assertSame(['Allow' => 'GET, HEAD, DELETE'], $e->getHeaders());
    }
}
