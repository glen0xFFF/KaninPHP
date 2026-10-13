<?php

declare(strict_types=1);

namespace Kanin\Validation;

use Kanin\Container;
use Kanin\Database\Connection;

/**
 * Checks an array of input against a set of rules.
 *
 *     $validator = Validator::make($data, [
 *         'name'  => 'required|string|max:100',
 *         'email' => ['required', 'email', 'unique:users,email'],
 *     ]);
 *
 *     if ($validator->fails()) {
 *         $validator->errors(); // ['email' => ['The email has already been taken.']]
 *     }
 *
 * Rules run in the order you write them. A field that is missing or empty
 * (null, '' or []) only fails when it has the "required" rule; every other
 * rule is skipped for empty fields.
 */
class Validator
{
    /** Number of parameters each rule needs at minimum, e.g. "between:1,10" needs 2. */
    private const RULES = [
        'required' => 0, 'nullable' => 0, 'string' => 0, 'email' => 0, 'numeric' => 0,
        'integer' => 0, 'min' => 1, 'max' => 1, 'between' => 2, 'confirmed' => 0,
        'in' => 1, 'same' => 1, 'alpha_num' => 0, 'url' => 0, 'unique' => 1, 'exists' => 1,
    ];

    /** Default messages. Size rules have one message per kind of value. */
    private const MESSAGES = [
        'required' => 'The :attribute field is required.',
        'string' => 'The :attribute must be a string.',
        'email' => 'The :attribute must be a valid email address.',
        'numeric' => 'The :attribute must be a number.',
        'integer' => 'The :attribute must be an integer.',
        'min.numeric' => 'The :attribute must be at least :min.',
        'min.array' => 'The :attribute must have at least :min items.',
        'min.string' => 'The :attribute must be at least :min characters.',
        'max.numeric' => 'The :attribute may not be greater than :max.',
        'max.array' => 'The :attribute may not have more than :max items.',
        'max.string' => 'The :attribute may not be greater than :max characters.',
        'between.numeric' => 'The :attribute must be between :min and :max.',
        'between.array' => 'The :attribute must have between :min and :max items.',
        'between.string' => 'The :attribute must be between :min and :max characters.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'in' => 'The selected :attribute is invalid.',
        'same' => 'The :attribute and :other must match.',
        'alpha_num' => 'The :attribute may only contain letters and numbers.',
        'url' => 'The :attribute must be a valid URL.',
        'unique' => 'The :attribute has already been taken.',
        'exists' => 'The selected :attribute is invalid.',
    ];

    /** @var array<string, array<string, list<string>>> field => [rule => parameters] */
    private array $rules = [];

    /** @var array<string, list<string>>|null Cached result; null until the data is checked. */
    private ?array $errors = null;

    /**
     * @param array<string, mixed> $data The input to check (e.g. $request->all()).
     * @param array<string, string|list<string>> $rules Field => 'rule|rule:param' or ['rule', 'rule:param'].
     * @param array<string, string> $messages Custom messages keyed 'field.rule' or 'rule'.
     * @param Connection|null $connection Database used by unique/exists (defaults to the app's).
     */
    public function __construct(
        private readonly array $data,
        array $rules,
        private readonly array $messages = [],
        private ?Connection $connection = null,
    ) {
        foreach ($rules as $field => $fieldRules) {
            $this->rules[(string) $field] = $this->parseRules($fieldRules);
        }
    }

    /**
     * Shortcut for `new Validator(...)` that reads nicely: Validator::make($data, $rules).
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     */
    public static function make(array $data, array $rules, array $messages = []): static
    {
        return new static($data, $rules, $messages);
    }

    /**
     * True when every field passes all of its rules.
     */
    public function passes(): bool
    {
        return $this->errors() === [];
    }

    /**
     * True when at least one field breaks a rule.
     */
    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * Error messages for the failed fields only: ['email' => ['...', '...']].
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        if ($this->errors === null) {
            $this->errors = [];

            foreach ($this->rules as $field => $rules) {
                $messages = $this->validateField($field, $rules);

                if ($messages !== []) {
                    $this->errors[$field] = $messages;
                }
            }
        }

        return $this->errors;
    }

    /**
     * The input limited to fields that have rules AND were sent.
     * Extra fields the client sent (like "is_admin") are dropped.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $validated = [];

        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    /**
     * Return the validated data, or throw a ValidationException (rendered as 422).
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors());
        }

        return $this->validated();
    }

    /**
     * Turn 'required|max:100' or ['required', 'max:100'] into ['required' => [], 'max' => ['100']].
     * Unknown rules or missing parameters fail loudly right away.
     */
    private function parseRules(string|array $rules): array
    {
        $parsed = [];

        foreach (is_string($rules) ? explode('|', $rules) : $rules as $rule) {
            if (! is_string($rule)) {
                throw new \InvalidArgumentException('Validation rules must be strings.');
            }

            [$name, $parameters] = array_pad(explode(':', trim($rule), 2), 2, null);

            if ($name === '') {
                continue;
            }

            if (! array_key_exists($name, self::RULES)) {
                throw new \InvalidArgumentException("Unknown validation rule [{$name}].");
            }

            $parameters = $parameters === null ? [] : array_map('trim', explode(',', $parameters));

            if (count($parameters) < self::RULES[$name]) {
                throw new \InvalidArgumentException("Validation rule [{$name}] requires at least " . self::RULES[$name] . ' parameter(s).');
            }

            if (in_array($name, ['min', 'max', 'between'], true) && array_filter($parameters, 'is_numeric') !== $parameters) {
                throw new \InvalidArgumentException("Validation rule [{$name}] requires numeric parameters.");
            }

            $parsed[$name] = $parameters;
        }

        return $parsed;
    }

    /** Run every rule of one field and collect the failure messages. */
    private function validateField(string $field, array $rules): array
    {
        $value = $this->data[$field] ?? null;

        if ($this->isEmpty($value)) {
            return isset($rules['required']) ? [$this->message($field, 'required', [], $value, $rules)] : [];
        }

        $messages = [];

        foreach ($rules as $rule => $parameters) {
            if (! $this->check($field, $value, $rule, $parameters, $rules)) {
                $messages[] = $this->message($field, $rule, $parameters, $value, $rules);
            }
        }

        return $messages;
    }

    /** Does a (non-empty) value pass one rule? */
    private function check(string $field, mixed $value, string $rule, array $parameters, array $rules): bool
    {
        $size = $this->size($value, $rules);

        return match ($rule) {
            'required', 'nullable' => true,
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'integer' => (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false,
            'min' => $size !== null && $size >= (float) $parameters[0],
            'max' => $size !== null && $size <= (float) $parameters[0],
            'between' => $size !== null && $size >= (float) $parameters[0] && $size <= (float) $parameters[1],
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value,
            'in' => is_scalar($value) && mb_check_encoding((string) $value, 'UTF-8') && in_array((string) $value, $parameters, true),
            'same' => array_key_exists($parameters[0], $this->data) && $this->data[$parameters[0]] === $value,
            'alpha_num' => (is_string($value) || is_int($value)) && preg_match('/^[\pL\pM\pN]+$/u', (string) $value) === 1,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
            'unique' => $this->isQueryable($value) && ! $this->rowExists($field, $value, $parameters, true),
            'exists' => $this->isQueryable($value) && $this->rowExists($field, $value, $parameters, false),
        };
    }

    /**
     * Look a value up in the database for the unique/exists rules.
     * Format: unique:table,column[,ignoreId[,idColumn]] and exists:table,column.
     * The column defaults to the field name. QueryBuilder validates the
     * table/column names and binds the value, so this is SQL-injection safe.
     */
    private function rowExists(string $field, string|int|float $value, array $parameters, bool $useIgnore): bool
    {
        $column = ($parameters[1] ?? '') !== '' ? $parameters[1] : $field;
        $query = $this->connection()->table($parameters[0])->where($column, $value);
        $ignoreId = $parameters[2] ?? '';

        if ($useIgnore && $ignoreId !== '') {
            $idColumn = ($parameters[3] ?? '') !== '' ? $parameters[3] : 'id';
            $query->where($idColumn, '!=', ctype_digit($ignoreId) ? (int) $ignoreId : $ignoreId);
        }

        return $query->exists();
    }

    /** The database connection: the one passed in, or the application's. */
    private function connection(): Connection
    {
        return $this->connection ??= Container::getInstance()->make(Connection::class);
    }

    /**
     * The "size" used by min/max/between: the number itself for numeric
     * fields, the item count for arrays, otherwise the character count.
     */
    private function size(mixed $value, array $rules): ?float
    {
        return match ($this->sizeType($value, $rules)) {
            'numeric' => (float) $value,
            'array' => (float) count($value),
            default => is_scalar($value) ? (float) mb_strlen((string) $value) : null,
        };
    }

    /** Which kind of size applies: 'numeric', 'array' or 'string'. */
    private function sizeType(mixed $value, array $rules): string
    {
        $hasNumericRule = isset($rules['numeric']) || isset($rules['integer']);

        return match (true) {
            $hasNumericRule && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) => 'numeric',
            is_array($value) => 'array',
            default => 'string',
        };
    }

    /** Build the message for a failed rule, using a custom message when given. */
    private function message(string $field, string $rule, array $parameters, mixed $value, array $rules): string
    {
        $key = in_array($rule, ['min', 'max', 'between'], true) ? $rule . '.' . $this->sizeType($value, $rules) : $rule;
        $template = $this->messages["{$field}.{$rule}"] ?? $this->messages[$rule] ?? self::MESSAGES[$key];

        return strtr($template, [
            ':attribute' => str_replace('_', ' ', $field),
            ':other' => str_replace('_', ' ', $parameters[0] ?? ''),
            ':min' => $parameters[0] ?? '',
            ':max' => $rule === 'between' ? ($parameters[1] ?? '') : ($parameters[0] ?? ''),
            ':values' => implode(', ', $parameters),
        ]);
    }

    /** Missing, null, blank strings and empty arrays count as "empty". */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /** Only plain values (not arrays, not booleans) can be looked up in the database. */
    private function isQueryable(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }
}
