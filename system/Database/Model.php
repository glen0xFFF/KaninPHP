<?php

declare(strict_types=1);

namespace Kanin\Database;

use Kanin\Container;
use Kanin\Http\NotFoundHttpException;

/**
 * A small "active record" model: one class per table, one object per row.
 *
 *     class User extends Model
 *     {
 *         protected array $fillable = ['name', 'email'];
 *     }
 *
 *     $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
 *     $user->name = 'Ada Lovelace';
 *     $user->save();
 */
abstract class Model implements \JsonSerializable
{
    /** Table name. Empty means "guess it from the class name" (User -> users). */
    protected string $table = '';

    /** The primary key column. */
    protected string $primaryKey = 'id';

    /** Columns that fill()/create() may set from user input (mass assignment). */
    protected array $fillable = [];

    /** Columns that are never included in toArray() or JSON output. */
    protected array $hidden = [];

    /** The row's column values. */
    protected array $attributes = [];

    /** True once this model has been loaded from, or saved to, the database. */
    public bool $exists = false;

    /** Connection shared by all models (null = ask the container). */
    private static ?Connection $connection = null;

    /** Build a new, unsaved model. Only $fillable keys are taken from $attributes. */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** Use this connection for every model (null goes back to the container's connection). */
    public static function setConnection(?Connection $connection): void
    {
        self::$connection = $connection;
    }

    /** The connection models use: the one set with setConnection(), else the container's. */
    public static function getConnection(): Connection
    {
        return self::$connection ?? Container::getInstance()->make(Connection::class);
    }

    /** Start a query whose results come back as models: User::query()->where(...)->get(). */
    public static function query(): QueryBuilder
    {
        $table = (new static())->getTable();

        return static::getConnection()->table($table)->setModel(static::class);
    }

    /** Every row of the table as models. */
    public static function all(): array
    {
        return static::query()->get();
    }

    /** Find a model by primary key, or null when it does not exist. */
    public static function find(int|string $id): ?static
    {
        $primaryKey = (new static())->primaryKey;

        return static::query()->find($id, $primaryKey);
    }

    /** Find a model by primary key, or throw a 404 NotFoundHttpException. */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new NotFoundHttpException(static::shortName() . ' not found.');
    }

    /** Start a model query with a where clause: User::where('email', $email)->first(). */
    public static function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where(...func_get_args());
    }

    /** Fill (fillable keys only), insert and return a new model. */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /** Build a model from a database row. All columns are kept (fillable is not applied). */
    public static function newFromRow(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->exists = true;

        return $model;
    }

    /** Set several attributes at once. Keys not listed in $fillable are ignored. */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable, true)) {
                $this->attributes[$key] = $value;
            }
        }

        return $this;
    }

    /** Insert the model (new) or update its row (existing). Returns true. */
    public function save(): bool
    {
        $values = $this->attributes;
        unset($values[$this->primaryKey]);

        if ($this->exists) {
            if ($values !== []) {
                $this->newQuery()->where($this->primaryKey, $this->getKey())->update($values);
            }

            return true;
        }

        $id = $this->newQuery()->insert($this->attributes);
        $this->attributes[$this->primaryKey] ??= $id;
        $this->exists = true;

        return true;
    }

    /** Delete this model's row. Returns false when the model was never saved. */
    public function delete(): bool
    {
        if (! $this->exists || $this->getKey() === null) {
            return false;
        }

        $deleted = $this->newQuery()->where($this->primaryKey, $this->getKey())->delete();
        $this->exists = false;

        return $deleted > 0;
    }

    /** The primary key value, or null before the model is saved. */
    public function getKey(): int|string|null
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /** The table name: $table, or the snake_case plural of the class name. */
    public function getTable(): string
    {
        return $this->table !== '' ? $this->table : static::guessTable(static::shortName());
    }

    /** The attributes as an array, without the $hidden ones. */
    public function toArray(): array
    {
        return array_diff_key($this->attributes, array_flip($this->hidden));
    }

    /** Called by json_encode(): the same as toArray(), so hidden fields never leak. */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Read an attribute: $user->name (null when missing). */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /** Write an attribute: $user->name = 'Ada' (not limited by $fillable). */
    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** Support isset($user->name). */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /** Support unset($user->name). */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** A plain (non-model) query builder for this model's table. */
    private function newQuery(): QueryBuilder
    {
        return static::getConnection()->table($this->getTable());
    }

    /** The class name without its namespace: App\Models\BlogPost -> BlogPost. */
    private static function shortName(): string
    {
        $class = static::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /** BlogPost -> blog_posts, Category -> categories, Box -> boxes. */
    private static function guessTable(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        return match (true) {
            preg_match('/[^aeiou]y$/', $snake) === 1 => substr($snake, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $snake) === 1 => $snake . 'es',
            default => $snake . 's',
        };
    }
}
