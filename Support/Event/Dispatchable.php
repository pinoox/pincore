<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Support\Event;

use Pinoox\Portal\Event;

trait Dispatchable
{
    /**
     * Dispatcher name: explicit `$eventName`, otherwise the class name.
     */
    public static function eventName(): string
    {
        $name = static::$eventName ?? null;

        return (is_string($name) && $name !== '') ? $name : static::class;
    }

    public static function dispatch(...$arguments): object
    {
        $instance = new static(...$arguments);
        return Event::dispatch($instance, static::eventName());
    }

    public static function dispatchIf(bool $condition, ...$arguments): ?object
    {
        return $condition ? static::dispatch(...$arguments) : null;
    }

    public static function dispatchUnless(bool $condition, ...$arguments): ?object
    {
        return !$condition ? static::dispatch(...$arguments) : null;
    }

    public static function subDispatch(string $subname, ...$arguments): object
    {
        $instance = new static(...$arguments);
        return Event::dispatch($instance, static::subname($subname));
    }

    public static function subFreeDispatch(string $eventName, ...$arguments): object
    {
        $instance = new static(...$arguments);
        return Event::dispatch($instance, $eventName);
    }

    public static function subname(string $subname): string
    {
        return static::eventName() . '.' . $subname;
    }
}