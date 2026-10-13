<?php

declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Kanin\Http\JsonResponse;
use Kanin\Http\Request;

/**
 * Test controller used by the router tests.
 */
class PostController
{
    public function index(): array
    {
        return ['action' => 'index'];
    }

    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['action' => 'store', 'title' => $request->input('title')], 201);
    }

    public function show(int $id): array
    {
        return ['action' => 'show', 'id' => $id];
    }

    public function update(Request $request, int $id): array
    {
        return ['action' => 'update', 'id' => $id, 'method' => $request->method()->value];
    }

    public function destroy(int $id): null
    {
        return null;
    }

    public function greet(string $name = 'guest'): string
    {
        return "Hello, {$name}";
    }

    private function secret(): string
    {
        return 'secret';
    }
}
