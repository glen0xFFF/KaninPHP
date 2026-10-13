<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Kanin\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER)');
    }

    public function test_unsupported_driver_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [oracle].');

        new Connection(['driver' => 'oracle']);
    }

    public function test_missing_driver_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [].');

        new Connection([]);
    }

    public function test_driver_name_is_available_without_connecting(): void
    {
        $mysql = new Connection(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'kanin']);

        $this->assertSame('mysql', $mysql->getDriverName());
        $this->assertSame('sqlite', $this->db->getDriverName());
    }

    public function test_pdo_is_created_lazily_once_with_safe_attributes(): void
    {
        $pdo = $this->db->getPdo();

        $this->assertSame($pdo, $this->db->getPdo());
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $this->assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function test_sqlite_enables_foreign_keys(): void
    {
        $this->assertSame(['foreign_keys' => 1], $this->db->selectOne('PRAGMA foreign_keys'));
    }

    public function test_insert_and_select_use_bound_values(): void
    {
        $this->assertTrue($this->db->insert('INSERT INTO users (name, age) VALUES (?, ?)', ['Ada', 36]));
        $this->assertTrue($this->db->insert('INSERT INTO users (name, age) VALUES (:name, :age)', ['name' => 'Alan', ':age' => 41]));

        $rows = $this->db->select('SELECT name, age FROM users ORDER BY id');

        $this->assertSame([['name' => 'Ada', 'age' => 36], ['name' => 'Alan', 'age' => 41]], $rows);
    }

    public function test_null_and_bool_values_are_bound_with_their_types(): void
    {
        $this->db->insert('INSERT INTO users (name, age) VALUES (?, ?)', ['Grace', null]);
        $this->db->insert('INSERT INTO users (name, age) VALUES (?, ?)', ['Linus', true]);

        $rows = $this->db->select('SELECT name, age FROM users ORDER BY id');

        $this->assertSame([['name' => 'Grace', 'age' => null], ['name' => 'Linus', 'age' => 1]], $rows);
    }

    public function test_bound_values_are_never_executed_as_sql(): void
    {
        $evil = "x'); DROP TABLE users; --";
        $this->db->insert('INSERT INTO users (name) VALUES (?)', [$evil]);

        $this->assertSame(['name' => $evil], $this->db->selectOne('SELECT name FROM users'));
    }

    public function test_select_one_returns_null_when_no_row_matches(): void
    {
        $this->assertNull($this->db->selectOne('SELECT * FROM users WHERE id = ?', [99]));
    }

    public function test_last_insert_id_is_a_string(): void
    {
        $this->db->insert('INSERT INTO users (name) VALUES (?)', ['Ada']);
        $this->db->insert('INSERT INTO users (name) VALUES (?)', ['Alan']);

        $this->assertSame('2', $this->db->lastInsertId());
    }

    public function test_update_and_delete_return_affected_row_counts(): void
    {
        $this->db->insert('INSERT INTO users (name, age) VALUES (?, ?)', ['Ada', 36]);
        $this->db->insert('INSERT INTO users (name, age) VALUES (?, ?)', ['Alan', 41]);

        $this->assertSame(2, $this->db->update('UPDATE users SET age = age + 1'));
        $this->assertSame(1, $this->db->delete('DELETE FROM users WHERE name = ?', ['Ada']));
        $this->assertSame(0, $this->db->delete('DELETE FROM users WHERE name = ?', ['Nobody']));
    }

    public function test_invalid_sql_throws_pdo_exception(): void
    {
        $this->expectException(\PDOException::class);

        $this->db->select('SELECT * FROM missing_table');
    }

    public function test_unprepared_runs_multiple_statements(): void
    {
        $this->assertTrue($this->db->unprepared(
            "INSERT INTO users (name) VALUES ('Ada'); INSERT INTO users (name) VALUES ('Alan');"
        ));

        $this->assertSame(['total' => 2], $this->db->selectOne('SELECT COUNT(*) AS total FROM users'));
    }

    public function test_transaction_commits_and_returns_the_callback_result(): void
    {
        $result = $this->db->transaction(function (Connection $db): string {
            $db->insert('INSERT INTO users (name) VALUES (?)', ['Ada']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['total' => 1], $this->db->selectOne('SELECT COUNT(*) AS total FROM users'));
    }

    public function test_transaction_rolls_back_and_rethrows_on_failure(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->insert('INSERT INTO users (name) VALUES (?)', ['Ada']);

                throw new \RuntimeException('boom');
            });
            $this->fail('The exception should have been re-thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['total' => 0], $this->db->selectOne('SELECT COUNT(*) AS total FROM users'));
        $this->assertFalse($this->db->getPdo()->inTransaction());
    }
}
