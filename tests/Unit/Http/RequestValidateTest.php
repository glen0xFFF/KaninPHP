<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\HttpException;
use Kanin\Http\Request;
use Kanin\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class RequestValidateTest extends TestCase
{
    public function test_validate_returns_only_validated_fields(): void
    {
        $request = Request::create('/users', 'POST', ['name' => 'Ada', 'is_admin' => true]);

        $this->assertSame(['name' => 'Ada'], $request->validate(['name' => 'required|string']));
    }

    public function test_validate_reads_query_and_json_body(): void
    {
        $request = Request::create(
            '/users?page=2',
            'POST',
            [],
            ['Content-Type' => 'application/json'],
            '{"email":"ada@example.com"}',
        );

        $this->assertSame(
            ['email' => 'ada@example.com', 'page' => '2'],
            $request->validate(['email' => 'required|email', 'page' => 'integer']),
        );
    }

    public function test_validate_throws_validation_exception_on_failure(): void
    {
        $request = Request::create('/users', 'POST', ['email' => 'nope']);

        try {
            $request->validate(['email' => 'required|email'], ['email.email' => 'Bad :attribute.']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['email' => ['Bad email.']], $e->errors());
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_validate_with_malformed_json_body_throws_400(): void
    {
        $request = Request::create('/users', 'POST', [], ['Content-Type' => 'application/json'], '{bad');

        try {
            $request->validate(['email' => 'required']);
            $this->fail('Expected an HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Malformed JSON body.', $e->getMessage());
        }
    }
}
