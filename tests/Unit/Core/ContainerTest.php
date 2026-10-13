<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Kanin\Container;
use Kanin\ContainerException;
use PHPUnit\Framework\TestCase;

interface ContainerTestLogger
{
}

final class ContainerTestFileLogger implements ContainerTestLogger
{
}

final class ContainerTestEngine
{
}

final class ContainerTestMailer
{
    public function __construct(public ContainerTestLogger $logger)
    {
    }
}

final class ContainerTestCar
{
    public function __construct(
        public ContainerTestEngine $engine,
        public string $color = 'red',
        public ?ContainerTestLogger $logger = null,
    ) {
    }
}

final class ContainerTestNeedsName
{
    public function __construct(public string $name)
    {
    }
}

final class ContainerTestNeedsUntyped
{
    public function __construct(public $value)
    {
    }
}

final class ContainerTestNeedsLogger
{
    public function __construct(public ContainerTestLogger $logger)
    {
    }
}

abstract class ContainerTestAbstract
{
}

final class ContainerTestCircularA
{
    public function __construct(public ContainerTestCircularB $b)
    {
    }
}

final class ContainerTestCircularB
{
    public function __construct(public ContainerTestCircularA $a)
    {
    }
}

final class ContainerTestController
{
    public function __construct(public ContainerTestEngine $engine)
    {
    }

    public function show(int $id, ContainerTestEngine $engine): array
    {
        return ['id' => $id, 'engine' => $engine];
    }

    public function price(float $amount): float
    {
        return $amount;
    }

    public function slug(string $slug): string
    {
        return $slug;
    }

    public function page(int $page = 1): int
    {
        return $page;
    }

    public static function version(): string
    {
        return 'v1';
    }

    private function secret(): string
    {
        return 'hidden';
    }
}

final class ContainerTestInvokable
{
    public function __invoke(ContainerTestEngine $engine): string
    {
        return $engine::class;
    }
}

final class ContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function test_bind_returns_a_new_instance_on_every_make(): void
    {
        $container = new Container();
        $container->bind(ContainerTestEngine::class);

        $first = $container->make(ContainerTestEngine::class);
        $second = $container->make(ContainerTestEngine::class);

        $this->assertInstanceOf(ContainerTestEngine::class, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_bind_closure_receives_the_container_and_parameters(): void
    {
        $container = new Container();
        $container->bind('greeting', fn (Container $c, array $parameters) => [$c, $parameters]);

        [$received, $parameters] = $container->make('greeting', ['name' => 'Ada']);

        $this->assertSame($container, $received);
        $this->assertSame(['name' => 'Ada'], $parameters);
    }

    public function test_bind_interface_to_a_class_name(): void
    {
        $container = new Container();
        $container->bind(ContainerTestLogger::class, ContainerTestFileLogger::class);

        $mailer = $container->make(ContainerTestMailer::class);

        $this->assertInstanceOf(ContainerTestFileLogger::class, $mailer->logger);
    }

    public function test_singleton_is_built_once_and_cached(): void
    {
        $container = new Container();
        $container->singleton(ContainerTestEngine::class);

        $this->assertSame(
            $container->make(ContainerTestEngine::class),
            $container->make(ContainerTestEngine::class),
        );
    }

    public function test_singleton_closure_runs_only_once(): void
    {
        $container = new Container();
        $calls = 0;
        $container->singleton('counter', function () use (&$calls): object {
            $calls++;

            return new \stdClass();
        });

        $container->make('counter');
        $container->make('counter');

        $this->assertSame(1, $calls);
    }

    public function test_rebinding_forgets_the_cached_singleton(): void
    {
        $container = new Container();
        $container->singleton('value', fn () => 'old');
        $container->make('value');

        $container->singleton('value', fn () => 'new');

        $this->assertSame('new', $container->make('value'));
    }

    public function test_instance_stores_and_returns_the_value(): void
    {
        $container = new Container();
        $engine = new ContainerTestEngine();

        $this->assertSame($engine, $container->instance(ContainerTestEngine::class, $engine));
        $this->assertSame($engine, $container->make(ContainerTestEngine::class));
    }

    public function test_instance_wins_over_an_existing_binding(): void
    {
        $container = new Container();
        $container->bind('name', fn () => 'from binding');
        $container->instance('name', 'from instance');

        $this->assertSame('from instance', $container->make('name'));
    }

    public function test_has_reports_bindings_and_instances(): void
    {
        $container = new Container();
        $container->bind('bound');
        $container->singleton('shared', fn () => 1);
        $container->instance('stored', null);

        $this->assertTrue($container->has('bound'));
        $this->assertTrue($container->has('shared'));
        $this->assertTrue($container->has('stored'));
        $this->assertFalse($container->has('missing'));
        $this->assertFalse($container->has(ContainerTestEngine::class));
    }

    public function test_make_autowires_a_class_that_was_never_bound(): void
    {
        $car = (new Container())->make(ContainerTestCar::class);

        $this->assertInstanceOf(ContainerTestCar::class, $car);
        $this->assertInstanceOf(ContainerTestEngine::class, $car->engine);
    }

    public function test_make_uses_default_values_and_null_for_nullable_types(): void
    {
        $car = (new Container())->make(ContainerTestCar::class);

        $this->assertSame('red', $car->color);
        $this->assertNull($car->logger);
    }

    public function test_make_uses_bindings_for_nested_dependencies(): void
    {
        $container = new Container();
        $container->singleton(ContainerTestLogger::class, ContainerTestFileLogger::class);

        $car = $container->make(ContainerTestCar::class);

        $this->assertSame($container->make(ContainerTestLogger::class), $car->logger);
    }

    public function test_make_prefers_parameters_passed_by_name(): void
    {
        $engine = new ContainerTestEngine();

        $car = (new Container())->make(ContainerTestCar::class, ['engine' => $engine, 'color' => 'blue']);

        $this->assertSame($engine, $car->engine);
        $this->assertSame('blue', $car->color);
    }

    public function test_make_passes_parameters_through_a_class_binding(): void
    {
        $container = new Container();
        $container->bind('named', ContainerTestNeedsName::class);

        $this->assertSame('Ada', $container->make('named', ['name' => 'Ada'])->name);
    }

    public function test_make_throws_for_an_unresolvable_builtin_parameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable dependency [$name] in class [' . ContainerTestNeedsName::class . ']');

        (new Container())->make(ContainerTestNeedsName::class);
    }

    public function test_make_throws_for_an_untyped_parameter_without_default(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable dependency [$value] in class [' . ContainerTestNeedsUntyped::class . ']');

        (new Container())->make(ContainerTestNeedsUntyped::class);
    }

    public function test_make_throws_for_an_unbound_interface_parameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable dependency [$logger] in class [' . ContainerTestNeedsLogger::class . ']');

        (new Container())->make(ContainerTestNeedsLogger::class);
    }

    public function test_make_throws_for_an_unknown_non_class_abstract(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Target [mailer] cannot be built: it is not bound and is not an instantiable class.');

        (new Container())->make('mailer');
    }

    public function test_make_throws_for_an_unbound_interface(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Target [' . ContainerTestLogger::class . '] cannot be built');

        (new Container())->make(ContainerTestLogger::class);
    }

    public function test_make_throws_for_an_abstract_class(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Target [' . ContainerTestAbstract::class . '] cannot be built');

        (new Container())->make(ContainerTestAbstract::class);
    }

    public function test_make_detects_circular_dependencies(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(sprintf(
            'Circular dependency detected: %s -> %s -> %s.',
            ContainerTestCircularA::class,
            ContainerTestCircularB::class,
            ContainerTestCircularA::class,
        ));

        (new Container())->make(ContainerTestCircularA::class);
    }

    public function test_call_invokes_a_closure_with_resolved_parameters(): void
    {
        $result = (new Container())->call(
            fn (ContainerTestEngine $engine, string $name, int $count = 3) => [$engine, $name, $count],
            ['name' => 'Ada'],
        );

        $this->assertInstanceOf(ContainerTestEngine::class, $result[0]);
        $this->assertSame('Ada', $result[1]);
        $this->assertSame(3, $result[2]);
    }

    public function test_call_makes_a_class_string_before_calling_the_method(): void
    {
        $result = (new Container())->call([ContainerTestController::class, 'show'], ['id' => '42']);

        $this->assertSame(42, $result['id']);
        $this->assertInstanceOf(ContainerTestEngine::class, $result['engine']);
    }

    public function test_call_uses_an_existing_object(): void
    {
        $controller = new ContainerTestController(new ContainerTestEngine());

        $this->assertSame('hello', (new Container())->call([$controller, 'slug'], ['slug' => 'hello']));
    }

    public function test_call_casts_numeric_strings_for_int_and_float_parameters(): void
    {
        $container = new Container();

        $this->assertSame(7, $container->call([ContainerTestController::class, 'page'], ['page' => '7']));
        $this->assertSame(9.5, $container->call([ContainerTestController::class, 'price'], ['amount' => '9.5']));
        $this->assertSame(10.0, $container->call([ContainerTestController::class, 'price'], ['amount' => '10']));
    }

    public function test_call_does_not_cast_values_for_string_parameters(): void
    {
        $this->assertSame('123', (new Container())->call([ContainerTestController::class, 'slug'], ['slug' => '123']));
    }

    public function test_call_uses_the_default_when_no_parameter_is_given(): void
    {
        $this->assertSame(1, (new Container())->call([ContainerTestController::class, 'page']));
    }

    public function test_call_supports_static_methods(): void
    {
        $this->assertSame('v1', (new Container())->call([ContainerTestController::class, 'version']));
    }

    public function test_call_supports_invokable_objects(): void
    {
        $this->assertSame(ContainerTestEngine::class, (new Container())->call(new ContainerTestInvokable()));
    }

    public function test_call_throws_for_a_missing_method(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Method [' . ContainerTestController::class . '::missing] does not exist.');

        (new Container())->call([ContainerTestController::class, 'missing']);
    }

    public function test_call_refuses_private_methods(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Method [' . ContainerTestController::class . '::secret] is not public.');

        (new Container())->call([ContainerTestController::class, 'secret']);
    }

    public function test_call_throws_for_an_unresolvable_method_parameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable dependency [$id] in class [' . ContainerTestController::class . ']');

        (new Container())->call([ContainerTestController::class, 'show']);
    }

    public function test_call_throws_for_an_unresolvable_closure_parameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable dependency [$name] in class [Closure]');

        (new Container())->call(fn (string $name) => $name);
    }

    public function test_get_instance_creates_a_container_when_none_was_set(): void
    {
        $container = Container::getInstance();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertSame($container, Container::getInstance());
    }

    public function test_set_instance_replaces_and_clears_the_global_container(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $this->assertSame($container, Container::getInstance());

        Container::setInstance(null);
        $this->assertNotSame($container, Container::getInstance());
    }

    public function test_container_exception_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new ContainerException('x'));
    }
}
