<?php

declare(strict_types=1);

namespace Kanin\Database;

/**
 * Builds safe SQL queries one method call at a time.
 *
 * Values are ALWAYS sent to the database as bound parameters (the "?" marks),
 * never glued into the SQL text. Table and column names cannot be bound, so
 * they are checked against a strict pattern and quoted instead.
 */
class QueryBuilder
{
    /** A name like `users`, `email` or `users.email`. */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** The only comparison operators where() accepts. */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    private array $columns = ['*'];
    /** @var list<array{boolean: string, sql: string, bindings: array}> */
    private array $wheres = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $model = null;

    /** Start a query for one table. Prefer $connection->table('users'). */
    public function __construct(private Connection $connection, private string $table)
    {
        $this->wrap($table); // validates the table name
    }

    /** Turn each fetched row into an instance of this model class (used by Model::query()). */
    public function setModel(string $modelClass): static
    {
        if (! class_exists($modelClass) || ! method_exists($modelClass, 'newFromRow')) {
            throw new \InvalidArgumentException("Model class [{$modelClass}] must define newFromRow().");
        }

        $this->model = $modelClass;

        return $this;
    }

    /** Choose the columns to fetch: select('id', 'name', 'email as contact'). */
    public function select(string ...$columns): static
    {
        $this->columns = $columns === [] ? ['*'] : array_map($this->compileColumn(...), $columns);

        return $this;
    }

    /** Add an "and" condition: where('id', 5) or where('age', '>=', 18). */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        return $this->addComparison('and', $column, $operator, $value);
    }

    /** Add an "or" condition. Same arguments as where(). */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        return $this->addComparison('or', $column, $operator, $value);
    }

    /** Keep rows whose column is one of the values. An empty list matches nothing. */
    public function whereIn(string $column, array $values): static
    {
        if ($values === []) {
            return $this->addWhere('and', '0 = 1', []);
        }

        $values = array_values($values);
        array_walk($values, fn (mixed $value) => $this->assertBindable($value));
        $marks = implode(', ', array_fill(0, count($values), '?'));

        return $this->addWhere('and', $this->wrap($column) . " in ({$marks})", $values);
    }

    /** Keep rows whose column IS NULL. */
    public function whereNull(string $column): static
    {
        return $this->addWhere('and', $this->wrap($column) . ' is null', []);
    }

    /** Keep rows whose column IS NOT NULL. */
    public function whereNotNull(string $column): static
    {
        return $this->addWhere('and', $this->wrap($column) . ' is not null', []);
    }

    /** Sort the results. Direction must be "asc" or "desc". */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("Invalid order direction [{$direction}]. Use asc or desc.");
        }

        $this->orders[] = $this->wrap($column) . ' ' . $direction;

        return $this;
    }

    /** Return at most this many rows. */
    public function limit(int $limit): static
    {
        $this->limit = $limit >= 0 ? $limit : throw new \InvalidArgumentException('Limit must not be negative.');

        return $this;
    }

    /** Skip this many rows first (used for paging). */
    public function offset(int $offset): static
    {
        $this->offset = $offset >= 0 ? $offset : throw new \InvalidArgumentException('Offset must not be negative.');

        return $this;
    }

    /** Run the query and return all rows (or model instances when a model is set). */
    public function get(): array
    {
        $rows = $this->connection->select($this->toSql(), $this->getBindings());

        if ($this->model === null) {
            return $rows;
        }

        return array_map(fn (array $row) => $this->model::newFromRow($row), $rows);
    }

    /** Return the first matching row, or null. The builder itself is not changed. */
    public function first(): array|Model|null
    {
        return (clone $this)->limit(1)->get()[0] ?? null;
    }

    /** Find one row by its id (or another column): find(5), find('ada', 'name'). */
    public function find(int|string $id, string $column = 'id'): array|Model|null
    {
        return (clone $this)->where($column, '=', $id)->first();
    }

    /** Count the matching rows (ignores order, limit and offset). */
    public function count(): int
    {
        $sql = 'select count(*) as ' . $this->wrap('aggregate') . ' from ' . $this->wrap($this->table)
            . $this->compileWheres();
        $row = $this->connection->selectOne($sql, $this->getBindings());

        return (int) ($row['aggregate'] ?? 0);
    }

    /** True when at least one row matches. */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /** Insert one row: insert(['name' => 'Ada']). Returns the new id (int when numeric). */
    public function insert(array $values): int|string
    {
        $this->assertValues($values, 'Insert');

        $columns = implode(', ', array_map($this->wrap(...), array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $sql = 'insert into ' . $this->wrap($this->table) . " ({$columns}) values ({$marks})";

        $this->connection->insert($sql, array_values($values));
        $id = $this->connection->lastInsertId();

        return ctype_digit($id) ? (int) $id : $id;
    }

    /** Update the matching rows and return how many changed. Requires a where clause. */
    public function update(array $values): int
    {
        $this->assertValues($values, 'Update');
        $this->assertScoped('update');

        $sets = array_map(fn (string $column) => $this->wrap($column) . ' = ?', array_keys($values));
        $sql = 'update ' . $this->wrap($this->table) . ' set ' . implode(', ', $sets) . $this->compileWheres();

        return $this->connection->update($sql, [...array_values($values), ...$this->getBindings()]);
    }

    /** Delete the matching rows and return how many were removed. Requires a where clause. */
    public function delete(): int
    {
        $this->assertScoped('delete');

        $sql = 'delete from ' . $this->wrap($this->table) . $this->compileWheres();

        return $this->connection->delete($sql, $this->getBindings());
    }

    /**
     * Fetch one page of results plus paging details.
     *
     * Returns ['data' => [...], 'meta' => ['current_page', 'per_page', 'total', 'last_page']].
     * $perPage is kept between 1 and 100, $page is at least 1.
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $total = (clone $this)->count();

        $data = (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** The SELECT statement this builder will run, with "?" placeholders. */
    public function toSql(): string
    {
        $sql = 'select ' . implode(', ', $this->columns) . ' from ' . $this->wrap($this->table);
        $sql .= $this->compileWheres();

        if ($this->orders !== []) {
            $sql .= ' order by ' . implode(', ', $this->orders);
        }

        return $sql . $this->compileLimitAndOffset();
    }

    /** The values that will be bound to the "?" placeholders, in order. */
    public function getBindings(): array
    {
        return array_merge([], ...array_column($this->wheres, 'bindings'));
    }

    /** Validate and store a "column operator ?" condition. */
    private function addComparison(string $boolean, string $column, mixed $operator, mixed $value): static
    {
        $operator = strtolower(trim((string) $operator));

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Invalid operator [{$operator}].");
        }

        $this->assertBindable($value);

        return $this->addWhere($boolean, $this->wrap($column) . " {$operator} ?", [$value]);
    }

    /** Store one compiled where fragment with its bindings. */
    private function addWhere(string $boolean, string $sql, array $bindings): static
    {
        $this->wheres[] = ['boolean' => $boolean, 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    /** Join the where fragments: ' where a = ? and b = ? or c is null'. */
    private function compileWheres(): string
    {
        $sql = '';

        foreach ($this->wheres as $index => $where) {
            $sql .= ($index === 0 ? ' where ' : " {$where['boolean']} ") . $where['sql'];
        }

        return $sql;
    }

    /** Limit and offset are validated integers, so they are written inline. */
    private function compileLimitAndOffset(): string
    {
        $limit = $this->limit;

        if ($limit === null && $this->offset !== null) {
            // SQLite and MySQL need a LIMIT before OFFSET; these mean "no limit".
            $limit = match ($this->connection->getDriverName()) {
                'sqlite' => -1,
                'mysql' => PHP_INT_MAX,
                default => null,
            };
        }

        $sql = $limit === null ? '' : " limit {$limit}";

        return $sql . ($this->offset === null ? '' : " offset {$this->offset}");
    }

    /** Validate one select() entry: '*', 'name', 'users.name' or 'name as alias'. */
    private function compileColumn(string $column): string
    {
        $column = trim($column);

        if ($column === '*') {
            return '*';
        }

        if (preg_match('/^(\S+)\s+as\s+(\S+)$/i', $column, $parts) === 1) {
            if (str_contains($parts[2], '.')) {
                throw new \InvalidArgumentException("Invalid column alias [{$parts[2]}].");
            }

            return $this->wrap($parts[1]) . ' as ' . $this->wrap($parts[2]);
        }

        return $this->wrap($column);
    }

    /** Validate an identifier and quote each dotted part: users.id -> "users"."id". */
    private function wrap(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException("Invalid identifier [{$identifier}].");
        }

        $quote = $this->connection->getDriverName() === 'mysql' ? '`' : '"';

        return $quote . str_replace('.', $quote . '.' . $quote, $identifier) . $quote;
    }

    /** Only scalars and null can be bound as query values. */
    private function assertBindable(mixed $value): void
    {
        if ($value !== null && ! is_scalar($value)) {
            $type = get_debug_type($value);

            throw new \InvalidArgumentException("Query values must be scalars or null, {$type} given.");
        }
    }

    /** insert()/update() need a non-empty column => value map of bindable values. */
    private function assertValues(array $values, string $action): void
    {
        if ($values === []) {
            throw new \InvalidArgumentException("{$action} values must not be empty.");
        }

        foreach ($values as $column => $value) {
            $this->wrap((string) $column);
            $this->assertBindable($value);
        }
    }

    /** Refuse UPDATE/DELETE without a where clause so a whole table is never changed by accident. */
    private function assertScoped(string $action): void
    {
        if ($this->wheres === []) {
            throw new \LogicException("Refusing to {$action} without a where clause.");
        }
    }
}
