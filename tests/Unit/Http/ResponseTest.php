<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_defaults_to_empty_200_plain_text(): void
    {
        $response = new Response();

        $this->assertSame('', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame(['Content-Type' => 'text/plain; charset=UTF-8'], $response->headers());
    }

    public function test_constructor_sets_content_status_and_headers(): void
    {
        $response = new Response('<p>hi</p>', 201, ['content-type' => 'text/html', 'X-Id' => '7']);

        $this->assertSame('<p>hi</p>', $response->getContent());
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeader('Content-Type'));
        $this->assertSame(['content-type' => 'text/html', 'X-Id' => '7'], $response->headers());
    }

    public function test_setters_are_fluent(): void
    {
        $response = new Response();

        $result = $response->setContent('changed')->setStatusCode(202)->header('X-A', '1');

        $this->assertSame($response, $result);
        $this->assertSame('changed', $response->getContent());
        $this->assertSame(202, $response->getStatusCode());
    }

    public function test_header_replaces_existing_header_case_insensitively(): void
    {
        $response = new Response('', 200, ['X-Token' => 'old']);

        $response->header('x-token', 'new');

        $this->assertSame('new', $response->getHeader('X-TOKEN'));
        $this->assertSame(['x-token' => 'new', 'Content-Type' => 'text/plain; charset=UTF-8'], $response->headers());
    }

    public function test_get_header_returns_null_when_missing(): void
    {
        $this->assertNull((new Response())->getHeader('X-Missing'));
    }

    public function test_header_refuses_line_breaks_in_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header [X-Evil] contains a line break.');

        (new Response())->header('X-Evil', "ok\r\nSet-Cookie: stolen=1");
    }

    public function test_header_refuses_invalid_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header name [Bad Name:].');

        (new Response())->header('Bad Name:', 'x');
    }

    public function test_header_refuses_name_with_trailing_line_feed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header name [X-Evil\n].');

        (new Response())->header("X-Evil\n", 'x');
    }

    public function test_header_refuses_nul_in_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header [X-Nul] contains a line break.');

        (new Response())->header('X-Nul', "a\0b");
    }

    public function test_status_code_below_100_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP status code [99].');

        new Response('', 99);
    }

    public function test_status_code_above_599_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP status code [600].');

        (new Response())->setStatusCode(600);
    }

    public function test_send_echoes_the_content(): void
    {
        $response = new Response('hello', 201);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        $this->assertSame('hello', $output);
    }
}
