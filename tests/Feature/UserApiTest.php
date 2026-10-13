<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class UserApiTest extends TestCase
{
    public function test_index_lists_the_seeded_users_with_pagination_meta(): void
    {
        $response = $this->getJson('/api/users');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame(
            ['ada@example.com', 'alan@example.com', 'grace@example.com'],
            array_column($body['data'], 'email')
        );
        $this->assertSame(['current_page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1], $body['meta']);
    }

    public function test_index_returns_the_requested_page(): void
    {
        $body = $this->decode($this->json('GET', '/api/users', ['per_page' => 2, 'page' => 2]));

        $this->assertSame(['grace@example.com'], array_column($body['data'], 'email'));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $body['meta']);
    }

    public function test_index_page_past_the_end_is_empty(): void
    {
        $body = $this->decode($this->getJson('/api/users?per_page=2&page=9'));

        $this->assertSame([], $body['data']);
        $this->assertSame(9, $body['meta']['current_page']);
        $this->assertSame(2, $body['meta']['last_page']);
    }

    public function test_index_clamps_per_page_and_page(): void
    {
        $body = $this->decode($this->getJson('/api/users?per_page=1000&page=-5'));

        $this->assertSame(100, $body['meta']['per_page']);
        $this->assertSame(1, $body['meta']['current_page']);
        $this->assertCount(3, $body['data']);
    }

    public function test_show_returns_one_user(): void
    {
        $response = $this->getJson('/api/users/1');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame(1, $body['id']);
        $this->assertSame('Ada Lovelace', $body['name']);
        $this->assertSame('ada@example.com', $body['email']);
        $this->assertArrayHasKey('created_at', $body);
    }

    public function test_show_unknown_user_returns_404(): void
    {
        $response = $this->getJson('/api/users/999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertIsString($this->decode($response)['message']);
    }

    public function test_show_with_non_numeric_id_returns_404(): void
    {
        $response = $this->getJson('/api/users/abc');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Not Found'], $this->decode($response));
    }

    public function test_store_creates_a_user_with_a_valid_token(): void
    {
        $response = $this->withToken()->postJson('/api/users', ['name' => 'Linus Torvalds', 'email' => 'linus@example.com']);

        $this->assertSame(201, $response->getStatusCode());
        $created = $this->decode($response);
        $this->assertSame('Linus Torvalds', $created['name']);
        $this->assertSame('linus@example.com', $created['email']);
        $this->assertSame(4, $created['id']);

        $shown = $this->decode($this->getJson('/api/users/4'));
        $this->assertSame('linus@example.com', $shown['email']);
    }

    public function test_store_ignores_fields_that_are_not_validated(): void
    {
        $response = $this->withToken()->postJson('/api/users', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'id' => 500,
            'created_at' => '1999-01-01 00:00:00',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(4, $this->decode($response)['id']);
        $this->assertSame(404, $this->getJson('/api/users/500')->getStatusCode());
        $this->assertNotSame('1999-01-01 00:00:00', $this->decode($this->getJson('/api/users/4'))['created_at']);
    }

    public function test_store_without_token_returns_401_and_creates_nothing(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'Eve', 'email' => 'eve@example.com']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthenticated.'], $this->decode($response));
        $this->assertSame('Bearer', $response->getHeader('WWW-Authenticate'));
        $this->assertSame(3, $this->decode($this->getJson('/api/users'))['meta']['total']);
    }

    public function test_store_with_wrong_token_returns_401(): void
    {
        $response = $this->withToken('not-the-token')->postJson('/api/users', ['name' => 'Eve', 'email' => 'eve@example.com']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthenticated.'], $this->decode($response));
        $this->assertSame(3, $this->decode($this->getJson('/api/users'))['meta']['total']);
    }

    public function test_update_and_destroy_without_token_return_401(): void
    {
        $update = $this->putJson('/api/users/1', ['name' => 'Hacked', 'email' => 'ada@example.com']);
        $destroy = $this->deleteJson('/api/users/1');

        $this->assertSame(401, $update->getStatusCode());
        $this->assertSame(401, $destroy->getStatusCode());
        $this->assertSame('Ada Lovelace', $this->decode($this->getJson('/api/users/1'))['name']);
    }

    public function test_store_with_missing_fields_returns_422(): void
    {
        $response = $this->withToken()->postJson('/api/users', []);

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('The given data was invalid.', $body['message']);
        $this->assertContains('The name field is required.', $body['errors']['name']);
        $this->assertContains('The email field is required.', $body['errors']['email']);
    }

    public function test_store_with_invalid_email_and_long_name_returns_422(): void
    {
        $response = $this->withToken()->postJson('/api/users', [
            'name' => str_repeat('a', 101),
            'email' => 'not-an-email',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        $this->assertContains('The name may not be greater than 100 characters.', $errors['name']);
        $this->assertContains('The email must be a valid email address.', $errors['email']);
    }

    public function test_store_with_duplicate_email_returns_422(): void
    {
        $response = $this->withToken()->postJson('/api/users', ['name' => 'Second Ada', 'email' => 'ada@example.com']);

        $this->assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        $this->assertSame(['The email has already been taken.'], $errors['email']);
        $this->assertArrayNotHasKey('name', $errors);
        $this->assertSame(3, $this->decode($this->getJson('/api/users'))['meta']['total']);
    }

    public function test_update_changes_the_user(): void
    {
        $response = $this->withToken()->putJson('/api/users/2', ['name' => 'Alan M. Turing', 'email' => 'turing@example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Alan M. Turing', $this->decode($response)['name']);

        $shown = $this->decode($this->getJson('/api/users/2'));
        $this->assertSame('Alan M. Turing', $shown['name']);
        $this->assertSame('turing@example.com', $shown['email']);
    }

    public function test_update_may_keep_the_users_own_email(): void
    {
        $response = $this->withToken()->patchJson('/api/users/1', ['name' => 'Ada King', 'email' => 'ada@example.com']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Ada King', $this->decode($this->getJson('/api/users/1'))['name']);
    }

    public function test_update_with_another_users_email_returns_422(): void
    {
        $response = $this->withToken()->putJson('/api/users/1', ['name' => 'Ada Lovelace', 'email' => 'grace@example.com']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['The email has already been taken.'], $this->decode($response)['errors']['email']);
        $this->assertSame('ada@example.com', $this->decode($this->getJson('/api/users/1'))['email']);
    }

    public function test_update_unknown_user_returns_404(): void
    {
        $response = $this->withToken()->putJson('/api/users/999', ['name' => 'Ghost', 'email' => 'ghost@example.com']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_destroy_deletes_the_user(): void
    {
        $response = $this->withToken()->deleteJson('/api/users/3');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame(404, $this->getJson('/api/users/3')->getStatusCode());
        $this->assertSame(2, $this->decode($this->getJson('/api/users'))['meta']['total']);
    }

    public function test_destroy_unknown_user_returns_404(): void
    {
        $response = $this->withToken()->deleteJson('/api/users/999');

        $this->assertSame(404, $response->getStatusCode());
    }
}
