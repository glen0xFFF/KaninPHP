<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Database\Model;
use Kanin\Database\QueryBuilder;
use Kanin\Http\NotFoundHttpException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Database\Fixtures\Account;
use Tests\Unit\Database\Fixtures\BlogPost;
use Tests\Unit\Database\Fixtures\Box;
use Tests\Unit\Database\Fixtures\Category;
use Tests\Unit\Database\Fixtures\User;

final class ModelTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->unprepared(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, password TEXT);'
            . "INSERT INTO users (name, email, password) VALUES ('Ada', 'ada@example.com', 'secret-1');"
            . "INSERT INTO users (name, email, password) VALUES ('Alan', 'alan@example.com', 'secret-2');"
            . 'CREATE TABLE legacy_accounts (account_id TEXT PRIMARY KEY, owner TEXT);'
        );
        Model::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Model::setConnection(null);
        Container::setInstance(null);
    }

    public function test_table_name_is_guessed_from_the_class_name(): void
    {
        $this->assertSame('users', (new User())->getTable());
        $this->assertSame('blog_posts', (new BlogPost())->getTable());
        $this->assertSame('categories', (new Category())->getTable());
        $this->assertSame('boxes', (new Box())->getTable());
    }

    public function test_table_property_overrides_the_guess(): void
    {
        $this->assertSame('legacy_accounts', (new Account())->getTable());
    }

    public function test_get_connection_returns_the_static_connection(): void
    {
        $this->assertSame($this->db, User::getConnection());
    }

    public function test_get_connection_falls_back_to_the_container(): void
    {
        $fromContainer = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container = new Container();
        $container->instance(Connection::class, $fromContainer);
        Container::setInstance($container);

        Model::setConnection(null);

        $this->assertSame($fromContainer, User::getConnection());
    }

    public function test_query_returns_a_builder_for_the_model_table(): void
    {
        $query = User::query();

        $this->assertInstanceOf(QueryBuilder::class, $query);
        $this->assertSame('select * from "users"', $query->toSql());
    }

    public function test_all_returns_hydrated_models(): void
    {
        $users = User::all();

        $this->assertCount(2, $users);
        $this->assertContainsOnlyInstancesOf(User::class, $users);
        $this->assertTrue($users[0]->exists);
        $this->assertSame('Ada', $users[0]->name);
    }

    public function test_find_returns_model_or_null(): void
    {
        $user = User::find(2);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(2, $user->getKey());
        $this->assertSame('Alan', $user->name);
        $this->assertNull(User::find(99));
    }

    public function test_find_uses_the_custom_primary_key(): void
    {
        Account::create(['account_id' => 'ACC-1', 'owner' => 'Ada']);

        $account = Account::find('ACC-1');

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame('ACC-1', $account->getKey());
        $this->assertSame('Ada', $account->owner);
    }

    public function test_find_or_fail_returns_the_model(): void
    {
        $this->assertSame('Ada', User::findOrFail(1)->name);
    }

    public function test_find_or_fail_throws_not_found(): void
    {
        try {
            User::findOrFail(99);
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('User not found.', $e->getMessage());
        }
    }

    public function test_where_starts_a_model_query(): void
    {
        $this->assertSame('Alan', User::where('email', 'alan@example.com')->first()->name);
        $this->assertSame(['Alan'], array_map(fn (User $u) => $u->name, User::where('id', '>', 1)->get()));
    }

    public function test_where_rejects_bad_operators_like_the_builder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator [drop].');

        User::where('id', 'drop', 1);
    }

    public function test_create_inserts_and_sets_the_key(): void
    {
        $user = User::create(['name' => 'Grace', 'email' => 'grace@example.com']);

        $this->assertTrue($user->exists);
        $this->assertSame(3, $user->getKey());
        $this->assertSame(['name' => 'Grace', 'email' => 'grace@example.com', 'id' => 3], $user->toArray());
        $this->assertSame('Grace', User::find(3)->name);
    }

    public function test_create_ignores_attributes_that_are_not_fillable(): void
    {
        $user = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'hacked', 'id' => 500]);

        $this->assertSame(3, $user->getKey());
        $this->assertNull(User::find(3)->password);
        $this->assertNull(User::find(500));
    }

    public function test_constructor_and_fill_only_accept_fillable_keys(): void
    {
        $user = new User(['name' => 'Ada', 'is_admin' => true]);
        $user->fill(['email' => 'ada@example.com', 'id' => 7]);

        $this->assertFalse($user->exists);
        $this->assertNull($user->getKey());
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $user->toArray());
    }

    public function test_fill_returns_the_same_model(): void
    {
        $user = new User();

        $this->assertSame($user, $user->fill(['name' => 'Ada']));
    }

    public function test_new_from_row_keeps_every_column_and_marks_existing(): void
    {
        $user = User::newFromRow(['id' => 9, 'name' => 'Ada', 'password' => 'x']);

        $this->assertTrue($user->exists);
        $this->assertSame(9, $user->getKey());
        $this->assertSame('x', $user->password);
    }

    public function test_save_updates_an_existing_model(): void
    {
        $user = User::find(1);
        $user->name = 'Ada Lovelace';

        $this->assertTrue($user->save());
        $this->assertSame('Ada Lovelace', User::find(1)->name);
        $this->assertSame('Alan', User::find(2)->name);
    }

    public function test_fill_then_save_updates_only_fillable_columns(): void
    {
        $user = User::find(1);
        $user->fill(['email' => 'lovelace@example.com', 'password' => 'nope'])->save();

        $fresh = User::find(1);
        $this->assertSame('lovelace@example.com', $fresh->email);
        $this->assertSame('secret-1', $fresh->password);
    }

    public function test_save_with_only_a_key_is_a_no_op(): void
    {
        $user = User::newFromRow(['id' => 1]);

        $this->assertTrue($user->save());
        $this->assertSame('Ada', User::find(1)->name);
    }

    public function test_save_inserts_a_new_model(): void
    {
        $user = new User(['name' => 'Linus', 'email' => 'linus@example.com']);

        $this->assertTrue($user->save());
        $this->assertTrue($user->exists);
        $this->assertSame(3, $user->getKey());
    }

    public function test_save_keeps_an_explicit_non_numeric_key(): void
    {
        $account = Account::create(['account_id' => 'ACC-7', 'owner' => 'Grace']);

        $this->assertSame('ACC-7', $account->getKey());
        $account->owner = 'Grace Hopper';
        $account->save();

        $this->assertSame('Grace Hopper', Account::find('ACC-7')->owner);
    }

    public function test_delete_removes_the_row(): void
    {
        $user = User::find(1);

        $this->assertTrue($user->delete());
        $this->assertFalse($user->exists);
        $this->assertNull(User::find(1));
        $this->assertNotNull(User::find(2));
    }

    public function test_delete_returns_false_for_unsaved_or_already_deleted_models(): void
    {
        $this->assertFalse((new User(['name' => 'Nobody']))->delete());

        $user = User::find(2);
        $user->delete();

        $this->assertFalse($user->delete());
        $this->assertSame(1, $this->db->table('users')->count());
    }

    public function test_hidden_attributes_never_appear_in_array_or_json(): void
    {
        $user = User::find(1);

        $this->assertSame(['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com'], $user->toArray());
        $this->assertSame($user->toArray(), $user->jsonSerialize());
        $this->assertSame('{"id":1,"name":"Ada","email":"ada@example.com"}', json_encode($user));
        $this->assertStringNotContainsString('secret', json_encode(User::all()));
    }

    public function test_hidden_attributes_are_still_readable_in_php(): void
    {
        $this->assertSame('secret-1', User::find(1)->password);
    }

    public function test_magic_get_set_isset_and_unset(): void
    {
        $user = new User();

        $this->assertNull($user->nickname);
        $this->assertFalse(isset($user->nickname));

        $user->nickname = 'Countess';
        $this->assertSame('Countess', $user->nickname);
        $this->assertTrue(isset($user->nickname));

        unset($user->nickname);
        $this->assertFalse(isset($user->nickname));
        $this->assertSame([], $user->toArray());
    }

    public function test_paginate_through_a_model_query_returns_models(): void
    {
        $page = User::query()->orderBy('id')->paginate(1, 2);

        $this->assertContainsOnlyInstancesOf(User::class, $page['data']);
        $this->assertSame('Alan', $page['data'][0]->name);
        $this->assertSame(['current_page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $page['meta']);
        $this->assertSame(
            '{"data":[{"id":2,"name":"Alan","email":"alan@example.com"}],"meta":{"current_page":2,"per_page":1,"total":2,"last_page":2}}',
            json_encode($page),
        );
    }
}
