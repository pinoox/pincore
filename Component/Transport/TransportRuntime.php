<?php

namespace Pinoox\Component\Transport;

/**
 * Runtime transport context for CLI and admin tools.
 *
 * Reads transport.* from the target app's app.php without persisting changes
 * to the active App config (never calls App::set()->save()).
 */
final class TransportRuntime
{
    private static ?string $activePackage = null;

    public static function use(?string $package): void
    {
        self::$activePackage = is_string($package) && $package !== '' ? $package : null;
    }

    public static function active(): ?string
    {
        return self::$activePackage;
    }

    public static function clear(): void
    {
        self::$activePackage = null;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runAs(string $package, callable $callback): mixed
    {
        $previous = self::$activePackage;
        self::use($package);

        try {
            return $callback();
        } finally {
            self::$activePackage = $previous;
        }
    }
}
