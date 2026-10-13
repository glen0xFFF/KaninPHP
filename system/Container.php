<?php

declare(strict_types=1);

namespace Kanin;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A small dependency injection container.
 *
 * The container is a "factory with a memory": you tell it how to build things
 * (bind / singleton / instance) and later ask for them with make(). When you
 * ask for a class it does not know, it reads the constructor with reflection
 * and builds every dependency for you ("autowiring").
 */
class Container
{
    /** The globally shared container (see getInstance()). */
    protected static ?Container $instance = null;

    /** @var array<string, array{concrete: Closure|string, shared: bool}> */
    protected array $bindings = [];

    /** @var array<string, mixed> Objects that were already built and are reused. */
    protected array $instances = [];

    /** @var list<string> Classes currently being built, used to detect loops. */
    protected array $buildStack = [];

    /**
     * Get the global container, creating an empty one the first time.
     */
    public static function getInstance(): static
    {
        return static::$instance ??= new static();
    }

    /**
     * Replace the global container (pass null to forget it, e.g. in tests).
     */
    public static function setInstance(?Container $container): void
    {
        static::$instance = $container;
    }

    /**
     * Register a binding that builds a NEW object every time you make() it.
     *
     * $concrete may be a closure `fn (Container $c, array $params) => ...`,
     * a class name, or null (meaning "build $abstract itself").
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->register($abstract, $concrete, false);
    }

    /**
     * Register a binding that is built ONCE and then reused forever.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->register($abstract, $concrete, true);
    }

    /**
     * Store an already-built value under a name and return it.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * Check whether something was registered under this name.
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Build (or fetch) whatever is registered under $abstract.
     *
     * $parameters lets you pass values by constructor parameter name,
     * for example make(Mailer::class, ['from' => 'me@example.com']).
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;

        if ($binding === null) {
            return $this->build($abstract, $parameters);
        }

        $object = $this->resolveBinding($abstract, $binding['concrete'], $parameters);

        if ($binding['shared']) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Call a closure or a [object|class-string, 'method'] pair, filling in
     * its parameters from $parameters (by name) or from the container.
     *
     * Numeric strings are turned into int/float when the parameter asks for
     * that type, so a route value "42" can reach `int $id`.
     */
    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            return $this->callMethod($callback, $parameters);
        }

        if (is_object($callback) && ! $callback instanceof Closure) {
            return $this->callMethod([$callback, '__invoke'], $parameters);
        }

        if (is_string($callback) && str_contains($callback, '::')) {
            return $this->callMethod(explode('::', $callback, 2), $parameters);
        }

        $function = new ReflectionFunction(Closure::fromCallable($callback));
        $context = $callback instanceof Closure ? 'Closure' : (string) $callback;

        return $function->invokeArgs($this->resolveParameters($function, $parameters, $context));
    }

    /**
     * Save a binding, forgetting any object cached for the same name.
     */
    protected function register(string $abstract, Closure|string|null $concrete, bool $shared): void
    {
        unset($this->instances[$abstract]);

        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => $shared];
    }

    /**
     * Turn a binding's "recipe" into a value.
     */
    protected function resolveBinding(string $abstract, Closure|string $concrete, array $parameters): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        return $concrete === $abstract
            ? $this->build($concrete, $parameters)
            : $this->make($concrete, $parameters);
    }

    /**
     * Create a class by reading its constructor and resolving each argument.
     */
    protected function build(string $class, array $parameters): object
    {
        if (! $this->isInstantiable($class)) {
            throw new ContainerException(
                "Target [{$class}] cannot be built: it is not bound and is not an instantiable class."
            );
        }

        if (in_array($class, $this->buildStack, true)) {
            $chain = implode(' -> ', [...$this->buildStack, $class]);

            throw new ContainerException("Circular dependency detected: {$chain}.");
        }

        $this->buildStack[] = $class;

        try {
            $reflector = new ReflectionClass($class);
            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                return $reflector->newInstance();
            }

            return $reflector->newInstanceArgs($this->resolveParameters($constructor, $parameters, $class));
        } finally {
            array_pop($this->buildStack);
        }
    }

    /**
     * Invoke an object (or class-string) method with resolved parameters.
     */
    protected function callMethod(array $callback, array $parameters): mixed
    {
        $callback = array_values($callback);

        if (count($callback) !== 2 || ! is_string($callback[1])) {
            throw new ContainerException('A method callback must look like [object|class-string, \'method\'].');
        }

        [$target, $method] = $callback;
        $object = is_string($target) ? $this->make($target) : $target;
        $class = is_object($object) ? $object::class : get_debug_type($target);

        if (! is_object($object) || ! method_exists($object, $method)) {
            throw new ContainerException("Method [{$class}::{$method}] does not exist.");
        }

        $reflector = new ReflectionMethod($object, $method);

        if (! $reflector->isPublic()) {
            throw new ContainerException("Method [{$class}::{$method}] is not public.");
        }

        $arguments = $this->resolveParameters($reflector, $parameters, $class);

        return $reflector->invokeArgs($reflector->isStatic() ? null : $object, $arguments);
    }

    /**
     * Resolve every parameter of a function, constructor or method, in order.
     *
     * @return list<mixed>
     */
    protected function resolveParameters(ReflectionFunctionAbstract $function, array $parameters, string $context): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $parameters, $context);
        }

        return $arguments;
    }

    /**
     * The 4 rules, tried in order: (1) a value passed by name, (2) a class or
     * interface type the container can build, (3) the default value,
     * (4) null for nullable types. Anything else is an error.
     */
    protected function resolveParameter(ReflectionParameter $parameter, array $parameters, string $context): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $isBuiltin = $type instanceof ReflectionNamedType && $type->isBuiltin();

        if (array_key_exists($name, $parameters)) {
            return $this->castNumeric($parameters[$name], $isBuiltin ? $typeName : null);
        }

        if ($typeName !== null && ! $isBuiltin && ($this->has($typeName) || $this->isInstantiable($typeName))) {
            return $this->make($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException("Unresolvable dependency [\${$name}] in class [{$context}]");
    }

    /**
     * Turn "42" into 42 for `int` parameters and "9.5" into 9.5 for `float`.
     */
    protected function castNumeric(mixed $value, ?string $type): mixed
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return $value;
        }

        if ($type === 'int' && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        return $type === 'float' ? (float) $value : $value;
    }

    /**
     * True when $class is a concrete class we can call `new` on.
     */
    protected function isInstantiable(string $class): bool
    {
        return class_exists($class) && (new ReflectionClass($class))->isInstantiable();
    }
}
