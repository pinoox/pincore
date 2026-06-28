<?php

namespace Pinoox\Component\Database\Factories;

trait HasFactory
{
    public static function factory(int|array|callable|null $count = null, array|callable $state = []): Factory
    {
        $factory = static::newFactory() ?? Factory::factoryForModel(static::class);

        if (is_int($count)) {
            $factory = $factory->count($count);
        } elseif ($count !== null) {
            $factory = $factory->state($count);
        }

        return $state !== [] ? $factory->state($state) : $factory;
    }

    protected static function newFactory(): ?Factory
    {
        return null;
    }
}
