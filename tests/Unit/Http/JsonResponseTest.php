<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Kanin\Http\JsonResponse;
use Kanin\Http\Response;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function test_json_response_encodes_data_with_json_content_type(): void
    {
        $response = new JsonResponse(['url' => 'https://example.com/a', 'name' => 'Zoë'], 201);

        $this->assertSame('{"url":"https://example.com/a","name":"Zoë"}', $response->getContent());
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertInstanceOf(Response::class, $response);
    }

    public function test_json_response_defaults_to_null_and_200(): void
    {
        $response = new JsonResponse();

        $this->assertSame('null', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getData());
    }

    public function test_json_response_keeps_extra_headers(): void
    {
        $response = new JsonResponse(['a' => 1], 401, ['WWW-Authenticate' => 'Bearer']);

        $this->assertSame('Bearer', $response->getHeader('WWW-Authenticate'));
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public function test_json_response_lets_caller_choose_a_json_content_type(): void
    {
        $response = new JsonResponse(['a' => 1], 200, ['content-type' => 'application/problem+json']);

        $this->assertSame('application/problem+json', $response->getHeader('Content-Type'));
    }

    public function test_json_response_with_204_has_empty_body(): void
    {
        $response = new JsonResponse(['ignored' => true], 204);

        $this->assertSame('', $response->getContent());
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($response->getData());
    }

    public function test_get_data_decodes_objects_as_associative_arrays(): void
    {
        $response = new JsonResponse(['user' => ['id' => 1, 'tags' => ['a', 'b']]]);

        $this->assertSame(['user' => ['id' => 1, 'tags' => ['a', 'b']]], $response->getData());
    }

    public function test_get_data_encodes_json_serializable_objects(): void
    {
        $item = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['id' => 5];
            }
        };

        $this->assertSame(['id' => 5], (new JsonResponse($item))->getData());
    }

    public function test_json_response_refuses_unencodable_data(): void
    {
        $this->expectException(\JsonException::class);

        new JsonResponse(['bad' => "\xB1\x31"]);
    }

    public function test_get_data_throws_when_content_is_not_json(): void
    {
        $response = (new JsonResponse())->setContent('{broken');

        $this->expectException(\JsonException::class);

        $response->getData();
    }
}
