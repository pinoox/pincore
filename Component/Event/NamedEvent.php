<?php

namespace Pinoox\Component\Event;

use ArrayAccess;

/**
 * String-named event with an arbitrary payload (no Event/Listener classes required).
 *
 *     event('order.register', ['id' => 12, 'user_id' => 4]);
 *     event_listen('order.register', function (NamedEvent $event) {
 *         $event->get('id');
 *         $event->id;
 *     });
 *
 * @implements ArrayAccess<string|int, mixed>
 */
class NamedEvent extends Event implements ArrayAccess
{
    /**
     * @param array<string|int, mixed> $payload
     */
    public function __construct(
        private readonly string $name,
        private array $payload = [],
    ) {
    }

    /**
     * @param mixed ...$payload
     */
    public static function from(string $name, mixed ...$payload): self
    {
        return new self($name, self::normalizePayload($payload));
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function all(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->payload);
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->payload[] = $value;

            return;
        }

        $this->payload[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->payload[$offset]);
    }

    /**
     * @param list<mixed> $args
     * @return array<string|int, mixed>
     */
    private static function normalizePayload(array $args): array
    {
        if ($args === []) {
            return [];
        }

        if (count($args) === 1 && is_array($args[0])) {
            return $args[0];
        }

        if (count($args) === 1) {
            return ['data' => $args[0]];
        }

        return $args;
    }
}
