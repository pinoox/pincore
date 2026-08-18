<?php

namespace Pinoox\Component\Event;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Turn class names / [class, method] into callables for the dispatcher.
 */
final class EventListener
{
    /**
     * @param callable|array|string $listener
     */
    public static function make(callable|array|string $listener): callable
    {
        if (is_string($listener)) {
            if (!class_exists($listener)) {
                throw new InvalidArgumentException(sprintf('Event listener class [%s] does not exist.', $listener));
            }

            return self::fromInstance(self::instantiate($listener));
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            if (!class_exists($listener[0])) {
                throw new InvalidArgumentException(sprintf('Event listener class [%s] does not exist.', $listener[0]));
            }

            $instance = self::instantiate($listener[0]);
            $method = $listener[1] ?? self::defaultMethod($instance);

            if ($method === null || !is_callable([$instance, $method])) {
                throw new InvalidArgumentException(sprintf(
                    'Event listener [%s] has no callable method [%s].',
                    $listener[0],
                    (string) $method,
                ));
            }

            return [$instance, $method];
        }

        if (is_callable($listener)) {
            return $listener;
        }

        throw new InvalidArgumentException('Event listener must be a callable, class name, or [class, method].');
    }

    /**
     * @return list<class-string>
     */
    public static function eventTypesFromCallable(callable $listener): array
    {
        try {
            $ref = self::reflectCallable($listener);
        } catch (\Throwable) {
            return [];
        }

        return $ref !== null ? self::eventTypesOf($ref) : [];
    }

    /**
     * @return list<class-string>
     */
    public static function eventTypesOf(ReflectionFunctionAbstract $method): array
    {
        $params = $method->getParameters();
        if ($params === []) {
            return [];
        }

        $type = $params[0]->getType();
        if ($type instanceof ReflectionNamedType) {
            return self::namedEventType($type);
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType) {
                    $names = array_merge($names, self::namedEventType($inner));
                }
            }

            return array_values(array_unique($names));
        }

        return [];
    }

    /**
     * @return list<class-string>
     */
    private static function namedEventType(ReflectionNamedType $type): array
    {
        if ($type->isBuiltin()) {
            return [];
        }

        $name = $type->getName();

        return $name !== '' ? [$name] : [];
    }

    private static function reflectCallable(callable $listener): ?ReflectionFunctionAbstract
    {
        if ($listener instanceof Closure) {
            return new ReflectionFunction($listener);
        }

        if (is_array($listener) && isset($listener[0], $listener[1])) {
            return new ReflectionMethod($listener[0], $listener[1]);
        }

        if (is_object($listener)) {
            return new ReflectionMethod($listener, '__invoke');
        }

        if (is_string($listener) && str_contains($listener, '::')) {
            [$class, $method] = explode('::', $listener, 2);

            return new ReflectionMethod($class, $method);
        }

        return null;
    }

    /**
     * @return callable
     */
    private static function fromInstance(object $instance): callable
    {
        if (is_callable($instance)) {
            return $instance;
        }

        $method = self::defaultMethod($instance);
        if ($method !== null && is_callable([$instance, $method])) {
            return [$instance, $method];
        }

        throw new InvalidArgumentException(sprintf(
            'Event listener [%s] must be invokable or define handle().',
            $instance::class,
        ));
    }

    private static function instantiate(string $class): object
    {
        return new $class();
    }

    private static function defaultMethod(object $instance): ?string
    {
        if (method_exists($instance, 'handle')) {
            return 'handle';
        }

        if (method_exists($instance, '__invoke')) {
            return '__invoke';
        }

        return null;
    }
}
