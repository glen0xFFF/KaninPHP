<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use Kanin\Container;
use Kanin\Database\Connection;
use Kanin\Validation\ValidationException;
use Kanin\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->connection->unprepared(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE);'
            . "INSERT INTO users (name, email) VALUES ('Ada', 'ada@example.com');"
            . "INSERT INTO users (name, email) VALUES ('Alan', 'alan@example.com');"
        );
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    /**
     * Validate a single field "field" holding $value against $rules.
     */
    private function errorsFor(mixed $value, string $rules, array $extra = []): array
    {
        $validator = new Validator(['field' => $value] + $extra, ['field' => $rules], [], $this->connection);

        return $validator->errors()['field'] ?? [];
    }

    public static function passingValues(): array
    {
        return [
            'required string' => ['Ada', 'required'],
            'required zero string' => ['0', 'required'],
            'required int zero' => [0, 'required'],
            'required false' => [false, 'required'],
            'string' => ['hello', 'string'],
            'email' => ['ada@example.com', 'email'],
            'numeric int' => [42, 'numeric'],
            'numeric float' => [4.2, 'numeric'],
            'numeric string' => ['4.2', 'numeric'],
            'integer int' => [7, 'integer'],
            'integer string' => ['-7', 'integer'],
            'min string' => ['abc', 'min:3'],
            'min multibyte' => ['ñññ', 'min:3'],
            'max string' => ['abc', 'max:3'],
            'min numeric' => ['10', 'numeric|min:10'],
            'max integer' => [5, 'integer|max:5'],
            'between string' => ['abcd', 'between:2,4'],
            'between numeric' => [3, 'numeric|between:1,5'],
            'min array' => [['a', 'b'], 'min:2'],
            'max array' => [['a'], 'max:1'],
            'in' => ['admin', 'in:admin,editor'],
            'in int' => [2, 'in:1,2,3'],
            'alpha_num' => ['abc123', 'alpha_num'],
            'alpha_num unicode' => ['Ñandú9', 'alpha_num'],
            'url http' => ['http://example.com', 'url'],
            'url https path' => ['https://example.com/a?b=c', 'url'],
            'nullable' => ['anything', 'nullable'],
        ];
    }

    #[DataProvider('passingValues')]
    public function test_rule_passes(mixed $value, string $rules): void
    {
        $this->assertSame([], $this->errorsFor($value, $rules));
    }

    public static function failingValues(): array
    {
        return [
            'required null' => [null, 'required', 'The field field is required.'],
            'required empty string' => ['', 'required', 'The field field is required.'],
            'required blank string' => ['   ', 'required', 'The field field is required.'],
            'required empty array' => [[], 'required', 'The field field is required.'],
            'string' => [123, 'string', 'The field must be a string.'],
            'email' => ['not-an-email', 'email', 'The field must be a valid email address.'],
            'email non string' => [['a@b.c'], 'email', 'The field must be a valid email address.'],
            'numeric' => ['abc', 'numeric', 'The field must be a number.'],
            'numeric bool' => [true, 'numeric', 'The field must be a number.'],
            'integer float' => [1.5, 'integer', 'The field must be an integer.'],
            'integer string' => ['1.5', 'integer', 'The field must be an integer.'],
            'min string' => ['ab', 'min:3', 'The field must be at least 3 characters.'],
            'max string' => ['abcd', 'max:3', 'The field may not be greater than 3 characters.'],
            'min numeric' => ['9', 'numeric|min:10', 'The field must be at least 10.'],
            'max numeric' => [6, 'integer|max:5', 'The field may not be greater than 5.'],
            'min array' => [['a'], 'min:2', 'The field must have at least 2 items.'],
            'max array' => [['a', 'b'], 'max:1', 'The field may not have more than 1 items.'],
            'between string' => ['a', 'between:2,4', 'The field must be between 2 and 4 characters.'],
            'between numeric' => [9, 'numeric|between:1,5', 'The field must be between 1 and 5.'],
            'between array' => [['a'], 'between:2,3', 'The field must have between 2 and 3 items.'],
            'in' => ['owner', 'in:admin,editor', 'The selected field is invalid.'],
            'in array' => [['admin'], 'in:admin', 'The selected field is invalid.'],
            'alpha_num' => ['abc-123', 'alpha_num', 'The field may only contain letters and numbers.'],
            'url' => ['not a url', 'url', 'The field must be a valid URL.'],
            'url javascript scheme' => ['javascript://alert(1)', 'url', 'The field must be a valid URL.'],
            'url ftp scheme' => ['ftp://example.com', 'url', 'The field must be a valid URL.'],
            'string invalid utf-8' => ["Bad�", 'string', 'The field must be a string.'],
            'in invalid utf-8' => ["ad�min", 'in:admin', 'The selected field is invalid.'],
        ];
    }

    #[DataProvider('failingValues')]
    public function test_rule_fails_with_default_message(mixed $value, string $rules, string $message): void
    {
        $this->assertSame([$message], $this->errorsFor($value, $rules));
    }

    public function test_empty_value_without_required_skips_every_other_rule(): void
    {
        $this->assertSame([], $this->errorsFor('', 'email|min:5'));
        $this->assertSame([], $this->errorsFor(null, 'nullable|integer'));
        $this->assertSame([], (new Validator([], ['missing' => 'string|max:3']))->errors());
    }

    public function test_required_failure_reports_only_the_required_message(): void
    {
        $this->assertSame(['The field field is required.'], $this->errorsFor('', 'required|email|min:5'));
    }

    public function test_every_failing_rule_is_reported_in_order(): void
    {
        $this->assertSame(
            ['The field must be a valid email address.', 'The field may not be greater than 3 characters.'],
            $this->errorsFor('abcdef', 'required|email|max:3'),
        );
    }

    public function test_attribute_underscores_become_spaces(): void
    {
        $errors = (new Validator([], ['first_name' => 'required']))->errors();

        $this->assertSame(['first_name' => ['The first name field is required.']], $errors);
    }

    public function test_confirmed_rule(): void
    {
        $ok = ['field' => 'secret', 'field_confirmation' => 'secret'];
        $this->assertSame([], (new Validator($ok, ['field' => 'confirmed']))->errors());

        $this->assertSame(
            ['The field confirmation does not match.'],
            $this->errorsFor('secret', 'confirmed', ['field_confirmation' => 'other']),
        );
        $this->assertSame(['The field confirmation does not match.'], $this->errorsFor('secret', 'confirmed'));
    }

    public function test_same_rule(): void
    {
        $this->assertSame([], $this->errorsFor('a', 'same:other_field', ['other_field' => 'a']));
        $this->assertSame(['The field and other field must match.'], $this->errorsFor('a', 'same:other_field', ['other_field' => 'b']));
        $this->assertSame(['The field and other field must match.'], $this->errorsFor('a', 'same:other_field'));
    }

    public function test_rules_can_be_given_as_an_array(): void
    {
        $validator = new Validator(['name' => 'Ada'], ['name' => ['required', 'string', 'max:2']]);

        $this->assertSame(['name' => ['The name may not be greater than 2 characters.']], $validator->errors());
    }

    public function test_unique_rule_uses_the_database(): void
    {
        $this->assertSame([], $this->errorsFor('new@example.com', 'unique:users,email'));
        $this->assertSame(['The field has already been taken.'], $this->errorsFor('ada@example.com', 'unique:users,email'));
    }

    public function test_unique_rule_defaults_the_column_to_the_field_name(): void
    {
        $validator = new Validator(['email' => 'ada@example.com'], ['email' => 'unique:users'], [], $this->connection);

        $this->assertSame(['email' => ['The email has already been taken.']], $validator->errors());
    }

    public function test_unique_rule_can_ignore_a_row_by_id(): void
    {
        $this->assertSame([], $this->errorsFor('ada@example.com', 'unique:users,email,1'));
        $this->assertSame([], $this->errorsFor('ada@example.com', 'unique:users,email,1,id'));
        $this->assertSame(['The field has already been taken.'], $this->errorsFor('ada@example.com', 'unique:users,email,2'));
    }

    public function test_exists_rule(): void
    {
        $this->assertSame([], $this->errorsFor('alan@example.com', 'exists:users,email'));
        $this->assertSame(['The selected field is invalid.'], $this->errorsFor('nobody@example.com', 'exists:users,email'));
    }

    public function test_database_rules_reject_non_scalar_values_without_querying(): void
    {
        $this->assertSame(['The field has already been taken.'], $this->errorsFor(['ada@example.com'], 'unique:users,email'));
        $this->assertSame(['The selected field is invalid.'], $this->errorsFor(true, 'exists:users,email'));
    }

    public function test_database_rules_reject_unsafe_table_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->errorsFor('x', 'unique:users;drop table users,email');
    }

    public function test_database_rules_fall_back_to_the_container_connection(): void
    {
        $container = new Container();
        $container->instance(Connection::class, $this->connection);
        Container::setInstance($container);

        $validator = Validator::make(['email' => 'ada@example.com'], ['email' => 'unique:users,email']);

        $this->assertSame(['email' => ['The email has already been taken.']], $validator->errors());
    }

    public function test_custom_messages_by_field_and_rule_then_by_rule(): void
    {
        $validator = Validator::make(
            ['name' => '', 'email' => ''],
            ['name' => 'required', 'email' => 'required'],
            ['name.required' => 'Tell us your :attribute!', 'required' => ':attribute is missing'],
        );

        $this->assertSame(
            ['name' => ['Tell us your name!'], 'email' => ['email is missing']],
            $validator->errors(),
        );
    }

    public function test_custom_messages_replace_size_placeholders(): void
    {
        $validator = Validator::make(['code' => 'abcdef'], ['code' => 'between:2,4'], ['between' => ':attribute: :min to :max']);

        $this->assertSame(['code' => ['code: 2 to 4']], $validator->errors());
    }

    public function test_passes_and_fails(): void
    {
        $this->assertTrue(Validator::make(['name' => 'Ada'], ['name' => 'required'])->passes());
        $this->assertFalse(Validator::make(['name' => 'Ada'], ['name' => 'required'])->fails());
        $this->assertFalse(Validator::make([], ['name' => 'required'])->passes());
        $this->assertTrue(Validator::make([], ['name' => 'required'])->fails());
    }

    public function test_validated_returns_only_ruled_fields_that_were_sent(): void
    {
        $validator = Validator::make(
            ['name' => 'Ada', 'is_admin' => true, 'bio' => null],
            ['name' => 'required', 'email' => 'nullable|email', 'bio' => 'nullable|string'],
        );

        $this->assertSame(['name' => 'Ada', 'bio' => null], $validator->validated());
    }

    public function test_validate_returns_validated_data_when_passing(): void
    {
        $data = Validator::make(['name' => 'Ada', 'role' => 'x'], ['name' => 'required|string'])->validate();

        $this->assertSame(['name' => 'Ada'], $data);
    }

    public function test_validate_throws_validation_exception_with_errors(): void
    {
        try {
            Validator::make(['email' => 'nope'], ['name' => 'required', 'email' => 'email'])->validate();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('The given data was invalid.', $e->getMessage());
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame([
                'name' => ['The name field is required.'],
                'email' => ['The email must be a valid email address.'],
            ], $e->errors());
        }
    }

    public function test_unknown_rule_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown validation rule [sometimes].');

        Validator::make(['name' => 'Ada'], ['name' => 'required|sometimes']);
    }

    public function test_rule_missing_parameters_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation rule [between] requires at least 2 parameter(s).');

        Validator::make(['age' => 3], ['age' => 'between:1']);
    }

    public function test_size_rule_with_non_numeric_parameter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation rule [max] requires numeric parameters.');

        Validator::make(['name' => 'Ada'], ['name' => 'max:ten']);
    }

    public function test_non_string_rule_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation rules must be strings.');

        Validator::make(['name' => 'Ada'], ['name' => ['required', 5]]);
    }
}
