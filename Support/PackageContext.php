<?php

namespace Pinoox\Support;

use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Portal\App\App;

/**
 * Resolves the active app package for migrations, seeders, factories, and patches.
 *
 * Priority: explicit argument → CLI/runtime (usePackage) → file path → App::package() → platform
 */
final class PackageContext
{
    private static ?string $runtimePackage = null;

    public static function use(?string $package): void
    {
        self::$runtimePackage = is_string($package) && $package !== '' ? $package : null;
    }

    public static function runtime(): ?string
    {
        return self::$runtimePackage;
    }

    /**
     * Run a callback as a data-layer package.
     *
     * Also sets TransportRuntime so UserModel/RoleModel/FileModel `app` columns
     * resolve to this package (or its transport.* override), not App::package()
     * — which on a host CLI/install is often the default route app (welcome).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runAs(string $package, callable $callback): mixed
    {
        $previous = self::$runtimePackage;
        $previousTransport = TransportRuntime::active();
        self::use($package);
        TransportRuntime::use($package);

        try {
            return $callback();
        } finally {
            self::$runtimePackage = $previous;
            TransportRuntime::use($previousTransport);
        }
    }

    public static function resolve(?string $explicit = null, ?string $sourceFile = null): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (self::$runtimePackage !== null && self::$runtimePackage !== '') {
            return self::$runtimePackage;
        }

        if (is_string($sourceFile) && $sourceFile !== '') {
            $fromFile = AppPackagePath::fromDataFile($sourceFile);

            if ($fromFile !== null) {
                return $fromFile;
            }
        }

        try {
            $fromApp = App::package();

            if (is_string($fromApp) && $fromApp !== '') {
                return $fromApp;
            }
        } catch (\Throwable) {
        }

        return 'platform';
    }
}
