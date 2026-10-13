<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Kanin\Database\Connection;
use Kanin\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->unprepared(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER);'
            . "INSERT INTO users (name, email, age) VALUES ('Ada', 'ada@example.com', 36);"
            . "INSERT INTO users (name, email, age) VALUES ('Alan', 'alan@example.com', 41);"
            . "INSERT INTO users (name, email, age) VALUES ('Grace', NULL, 85);"
        );
    }

    private function users(): QueryBuilder
    {
        return $this->db->table('users');
    }

    public function test_connection_table_returns_a_query_builder(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, $this->db->table('users'));
    }

    public function test_default_select_sql(): void
    {
        $this->assertSame('select * from "users"', $this->users()->toSql());
        $this->assertSame([], $this->users()->getBindings());
    }

    public function test_full_sql_shape_and_bindings(): void
    {
        $query = $this->users()
            ->where('id', 1)
            ->where('name', 'like', 'A%')
            ->whereNull('x')
            ->orWhere('age', '>=', 18)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->offset(5);

        $this->assertSame(
            'select * from "users" where "id" = ? and "name" like ? and "x" is null or "age" >= ? order by "id" desc limit 10 offset 5',
            $query->toSql(),
        );
        $this->assertSame([1, 'A%', 18], $query->getBindings());
    }

    public function test_or_where_with_two_arguments_uses_equals(): void
    {
        $query = $this->users()->where('id', 1)->orWhere('id', 2);

        $this->assertSame('select * from "users" where "id" = ? or "id" = ?', $query->toSql());
        $this->assertSame([1, 2], $query->getBindings());
    }

    public function test_select_columns_aliases_and_dotted_names(): void
    {
        $query = $this->users()->select('id', 'users.name', 'email AS contact', '*');

        $this->assertSame('select "id", "users"."name", "email" as "contact", * from "users"', $query->toSql());
    }

    public function test_select_without_columns_means_all_columns(): void
    {
        $this->assertSame('select * from "users"', $this->users()->select()->toSql());
    }

    public function test_where_in_and_empty_where_in(): void
    {
        $query = $this->users()->whereIn('id', [1, 3]);
        $this->assertSame('select * from "users" where "id" in (?, ?)', $query->toSql());
        $this->assertSame([1, 3], $query->getBindings());

        $empty = $this->users()->whereIn('id', []);
        $this->assertSame('select * from "users" where 0 = 1', $empty->toSql());
        $this->assertSame([], $empty->get());
    }

    public function test_where_not_null_sql(): void
    {
        $this->assertSame('select * from "users" where "email" is not null', $this->users()->whereNotNull('email')->toSql());
    }

    public function test_operators_and_directions_are_case_insensitive_and_lowercased(): void
    {
        $query = $this->users()->where('name', 'NOT LIKE', 'A%')->orderBy('name', 'DESC');

        $this->assertSame('select * from "users" where "name" not like ? order by "name" desc', $query->toSql());
    }

    public function test_multiple_order_by_clauses(): void
    {
        $query = $this->users()->orderBy('age')->orderBy('id', 'desc');

        $this->assertSame('select * from "users" order by "age" asc, "id" desc', $query->toSql());
    }

    public function test_mysql_uses_backticks(): void
    {
        $mysql = new Connection(['driver' => 'mysql', 'database' => 'kanin']);
        $query = $mysql->table('users')->select('users.id')->where('email', 'a@b.c');

        $this->assertSame('select `users`.`id` from `users` where `email` = ?', $query->toSql());
    }

    public function test_offset_without_limit_is_valid_for_each_driver(): void
    {
        $mysql = new Connection(['driver' => 'mysql', 'database' => 'kanin']);
        $pgsql = new Connection(['driver' => 'pgsql', 'database' => 'kanin']);

        $this->assertSame('select * from "users" limit -1 offset 2', $this->users()->offset(2)->toSql());
        $this->assertSame('select * from `users` limit ' . PHP_INT_MAX . ' offset 2', $mysql->table('users')->offset(2)->toSql());
        $this->assertSame('select * from "users" offset 2', $pgsql->table('users')->offset(2)->toSql());
        $this->assertSame(['Grace'], array_column($this->users()->orderBy('id')->offset(2)->get(), 'name'));
    }

    public static function badIdentifiers(): array
    {
        return [
            'injection' => ['id; DROP TABLE users'],
            'quote' => ['na"me'],
            'space' => ['first name'],
            'leading digit' => ['1name'],
            'two dots' => ['a.b.c'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badIdentifiers')]
    public function test_where_rejects_bad_column_names(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid identifier [{$column}].");

        $this->users()->where($column, 1);
    }

    public function test_bad_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier [users; --].');

        $this->db->table('users; --');
    }

    public function test_select_rejects_bad_columns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier [count(*)].');

        $this->users()->select('count(*)');
    }

    public function test_select_rejects_dotted_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column alias [a.b].');

        $this->users()->select('name as a.b');
    }

    public function test_order_by_where_in_and_null_checks_reject_bad_columns(): void
    {
        $calls = [
            fn () => $this->users()->orderBy('id desc'),
            fn () => $this->users()->whereIn('id) or (1', [1]),
            fn () => $this->users()->whereNull('x--'),
            fn () => $this->users()->whereNotNull('x--'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('Invalid identifier [', $e->getMessage());
            }
        }
    }

    public function test_unknown_operator_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator [or 1=1 --].');

        $this->users()->where('id', 'or 1=1 --', 1);
    }

    public function test_or_where_rejects_unknown_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator [in].');

        $this->users()->orWhere('id', 'in', 1);
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid order direction [sideways]. Use asc or desc.');

        $this->users()->orderBy('id', 'sideways');
    }

    public function test_non_scalar_values_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query values must be scalars or null, array given.');

        $this->users()->where('id', [1, 2]);
    }

    public function test_negative_limit_and_offset_are_rejected(): void
    {
        try {
            $this->users()->limit(-1);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Limit must not be negative.', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must not be negative.');

        $this->users()->offset(-5);
    }

    public function test_get_first_and_find_run_real_queries(): void
    {
        $this->assertSame(['Alan', 'Grace'], array_column($this->users()->where('age', '>', 40)->orderBy('id')->get(), 'name'));
        $this->assertSame('Ada', $this->users()->orderBy('id')->first()['name']);
        $this->assertSame('Grace', $this->users()->find(3)['name']);
        $this->assertSame('Alan', $this->users()->find('alan@example.com', 'email')['name']);
        $this->assertNull($this->users()->find(99));
        $this->assertNull($this->users()->where('name', 'Nobody')->first());
    }

    public function test_first_does_not_change_the_builder(): void
    {
        $query = $this->users();
        $query->first();

        $this->assertSame('select * from "users"', $query->toSql());
        $this->assertCount(3, $query->get());
    }

    public function test_injection_attempts_in_values_are_harmless(): void
    {
        $rows = $this->users()->where('name', "' OR '1'='1")->get();

        $this->assertSame([], $rows);
        $this->assertSame(3, $this->users()->count());
    }

    public function test_count_and_exists(): void
    {
        $this->assertSame(3, $this->users()->count());
        $this->assertSame(1, $this->users()->whereNull('email')->count());
        $this->assertSame(2, $this->users()->where('name', 'like', 'A%')->limit(1)->count());
        $this->assertTrue($this->users()->where('id', 1)->exists());
        $this->assertFalse($this->users()->where('id', 99)->exists());
    }

    public function test_insert_returns_integer_id(): void
    {
        $id = $this->users()->insert(['name' => 'Linus', 'email' => 'linus@example.com', 'age' => null]);

        $this->assertSame(4, $id);
        $this->assertSame(['id' => 4, 'name' => 'Linus', 'email' => 'linus@example.com', 'age' => null], $this->users()->find(4));
    }

    public function test_insert_rejects_empty_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insert values must not be empty.');

        $this->users()->insert([]);
    }

    public function test_insert_rejects_bad_column_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier [name) VALUES (1); --].');

        $this->users()->insert(['name) VALUES (1); --' => 'x']);
    }

    public function test_update_changes_only_matching_rows(): void
    {
        $changed = $this->users()->where('id', 1)->update(['name' => 'Ada Lovelace', 'age' => 37]);

        $this->assertSame(1, $changed);
        $this->assertSame('Ada Lovelace', $this->users()->find(1)['name']);
        $this->assertSame('Alan', $this->users()->find(2)['name']);
    }

    public function test_update_without_where_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Refusing to update without a where clause.');

        $this->users()->update(['name' => 'Everyone']);
    }

    public function test_update_rejects_empty_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Update values must not be empty.');

        $this->users()->where('id', 1)->update([]);
    }

    public function test_delete_removes_only_matching_rows(): void
    {
        $this->assertSame(1, $this->users()->where('id', 2)->delete());
        $this->assertSame(2, $this->users()->count());
    }

    public function test_delete_without_where_is_refused(): void
    {
        try {
            $this->users()->orderBy('id')->limit(1)->delete();
            $this->fail('Expected LogicException.');
        } catch (\LogicException $e) {
            $this->assertSame('Refusing to delete without a where clause.', $e->getMessage());
        }

        $this->assertSame(3, $this->users()->count());
    }

    public function test_paginate_returns_data_and_meta(): void
    {
        $page = $this->users()->orderBy('id')->paginate(2, 2);

        $this->assertSame(['Grace'], array_column($page['data'], 'name'));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page['meta']);
    }

    public function test_paginate_shares_where_clauses_between_count_and_page(): void
    {
        $page = $this->users()->where('name', 'like', 'A%')->orderBy('id')->paginate(1, 1);

        $this->assertSame(['Ada'], array_column($page['data'], 'name'));
        $this->assertSame(['current_page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $page['meta']);
    }

    public function test_paginate_clamps_per_page_and_page(): void
    {
        $tooSmall = $this->users()->orderBy('id')->paginate(0, -3);
        $this->assertSame(['current_page' => 1, 'per_page' => 1, 'total' => 3, 'last_page' => 3], $tooSmall['meta']);
        $this->assertSame(['Ada'], array_column($tooSmall['data'], 'name'));

        $tooBig = $this->users()->paginate(500);
        $this->assertSame(100, $tooBig['meta']['per_page']);
        $this->assertCount(3, $tooBig['data']);
    }

    public function test_paginate_empty_result_has_last_page_one(): void
    {
        $page = $this->users()->where('id', 99)->paginate();

        $this->assertSame([], $page['data']);
        $this->assertSame(['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1], $page['meta']);
    }

    public function test_paginate_past_the_last_page_returns_no_rows(): void
    {
        $page = $this->users()->paginate(2, 9);

        $this->assertSame([], $page['data']);
        $this->assertSame(9, $page['meta']['current_page']);
        $this->assertSame(2, $page['meta']['last_page']);
    }

    public function test_set_model_hydrates_rows_through_new_from_row(): void
    {
        $model = new class {
            public static function newFromRow(array $row): object
            {
                return (object) ['hydrated' => $row['name']];
            }
        };

        $rows = $this->users()->setModel($model::class)->orderBy('id')->limit(2)->get();

        $this->assertEquals([(object) ['hydrated' => 'Ada'], (object) ['hydrated' => 'Alan']], $rows);
    }

    public function test_set_model_rejects_classes_without_new_from_row(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class [stdClass] must define newFromRow().');

        $this->users()->setModel(\stdClass::class);
    }

    public function test_set_model_rejects_unknown_classes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class [App\Models\Missing] must define newFromRow().');

        $this->users()->setModel('App\Models\Missing');
    }
}
