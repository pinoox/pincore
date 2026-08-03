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

namespace Pinoox\Component\Router\Parameter;

/**
 * Named regex patterns for route parameters ({id:int}, {user:username}, …).
 */
final class ParameterPatterns
{
    /** @var array<string, string> */
    private static array $patterns = [];

    private static bool $booted = false;

    /**
     * @return array<string, string>
     */
    public static function builtins(): array
    {
        return [
            'int' => '[0-9]+',
            'number' => '[0-9]+(?:\\.[0-9]+)?',
            'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'ulid' => '[0-9A-HJKMNP-TV-Z]{26}',
            'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'alpha' => '[a-zA-Z]+',
            'alnum' => '[a-zA-Z0-9]+',
            'hex' => '[0-9a-fA-F]+',
            'email' => '[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}',
            'domain' => '(?:[a-zA-Z0-9](?:[a-zA-Z0-9\\-]{0,61}[a-zA-Z0-9])?\\.)+[a-zA-Z]{2,}',
            'ip' => '(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}',
            'url' => 'https?://[^\\s/$.?#].[^\\s]*',
        ];
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$patterns = self::builtins();
        self::$booted = true;
    }

    public static function pattern(string $name, string $regex): void
    {
        self::boot();
        self::$patterns[$name] = $regex;
    }

    /**
     * @param array<string, string> $patterns
     */
    public static function patterns(array $patterns): void
    {
        self::boot();
        foreach ($patterns as $name => $regex) {
            self::$patterns[(string) $name] = (string) $regex;
        }
    }

    public static function get(string $name): ?string
    {
        self::boot();

        return self::$patterns[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        self::boot();

        return isset(self::$patterns[$name]);
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        self::boot();

        return self::$patterns;
    }

    /**
     * Remove all custom patterns and restore built-ins.
     */
    public static function clear(): void
    {
        self::$patterns = self::builtins();
        self::$booted = true;
    }

    /**
     * Reset state (tests).
     */
    public static function reset(): void
    {
        self::$patterns = [];
        self::$booted = false;
    }
}
