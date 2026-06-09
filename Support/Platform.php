<?php

namespace Pinoox\Support;

/**
 * Core platform identity — logical package/connection/transport scope.
 *
 * The physical framework directory is resolved via {@see PINOOX_CORE_PATH}
 * (Composer package `pinoox/pincore`, typically `vendor/pinoox/pincore`).
 */
final class Platform
{
    /** Logical package name for migrations, DB connection, transport scope, CLI, etc. */
    public const PACKAGE = 'platform';

    /** Physical core directory name under the project root. */
    public const CORE_DIR = 'pincore';

    public static function isPackage(?string $package): bool
    {
        return $package === self::PACKAGE;
    }
}
