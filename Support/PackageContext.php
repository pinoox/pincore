<?php

namespace Pinoox\Support;

use Pinoox\Portal\App\App;

/**
 * Resolves the active app package for migrations, seeders, and patches.
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
