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

namespace Pinoox\Component\Foundation;

use Pinoox\Component\Kernel\Loader;
use RuntimeException;

/**
 * Resolves project root, pincore (Composer package), storage, and runtime config paths.
 */
final class ApplicationPaths
{
    private static ?string $basePath = null;

    private static ?string $pincorePath = null;

    private static ?string $storagePath = null;

    private static ?string $appsPath = null;

    public static function configure(string $basePath, ?string $pincorePath = null): void
    {
        self::$basePath = self::normalizeDir($basePath);
        self::$pincorePath = self::normalizeDir(
            $pincorePath ?? self::detectPincorePath(self::$basePath)
        );
        self::$storagePath = self::$basePath . '/storage';
        self::$appsPath = self::$basePath . '/apps';

        self::ensureStorageDirs();

        Loader::setBasePath(self::$basePath);
    }

    public static function basePath(): string
    {
        self::assertConfigured();

        return self::$basePath;
    }

    /** Installed pincore package (vendor/pinoox/pincore). */
    public static function pincorePath(): string
    {
        self::assertConfigured();

        return self::$pincorePath;
    }

    /** @deprecated Use pincorePath() */
    public static function frameworkPath(): string
    {
        return self::pincorePath();
    }

    public static function storagePath(): string
    {
        self::assertConfigured();

        return self::$storagePath;
    }

    public static function appsPath(): string
    {
        self::assertConfigured();

        return self::$appsPath;
    }

    public static function runtimeConfigPath(string $relative = ''): string
    {
        self::assertConfigured();

        $path = self::$storagePath . '/pinoox/config';

        return $relative !== '' ? $path . '/' . ltrim(str_replace('\\', '/', $relative), '/') : $path;
    }

    public static function pincoreConfigPath(string $relative = ''): string
    {
        self::assertConfigured();

        $path = rtrim(self::$pincorePath, '/') . '/config';

        return $relative !== '' ? $path . '/' . ltrim(str_replace('\\', '/', $relative), '/') : $path;
    }

    /** @deprecated Use pincoreConfigPath() */
    public static function frameworkConfigPath(string $relative = ''): string
    {
        return self::pincoreConfigPath($relative);
    }

    public static function projectConfigPath(string $relative = ''): string
    {
        self::assertConfigured();

        $path = self::$basePath . '/config';

        return $relative !== '' ? $path . '/' . ltrim(str_replace('\\', '/', $relative), '/') : $path;
    }

    /**
     * Legacy baked config under pincore/pinker (pre-storage migration).
     */
    public static function legacyCorePinkerPath(string $relative = ''): string
    {
        self::assertConfigured();

        $path = rtrim(self::$pincorePath, '/') . '/pinker';

        return $relative !== '' ? $path . '/' . ltrim(str_replace('\\', '/', $relative), '/') : $path;
    }

    /**
     * Pincore lives only under vendor (Laravel-style). Use PINOOX_PINCORE_PATH to override in tests.
     */
    private static function detectPincorePath(string $basePath): string
    {
        $root = rtrim($basePath, '/');

        $override = getenv('PINOOX_PINCORE_PATH') ?: ($_ENV['PINOOX_PINCORE_PATH'] ?? '');
        if ($override !== '' && is_file(rtrim($override, '/') . '/bootstrap/requirements.php')) {
            return rtrim($override, '/');
        }

        $vendor = $root . '/vendor/pinoox/pincore';
        if (is_dir($vendor) && is_file($vendor . '/bootstrap/requirements.php')) {
            return $vendor;
        }

        throw new RuntimeException(
            'Pinoox pincore not found. Run: composer install'
        );
    }

    private static function ensureStorageDirs(): void
    {
        $dirs = [
            self::$storagePath,
            self::runtimeConfigPath(),
            self::runtimeConfigPath('app'),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private static function normalizeDir(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/') . '/';
    }

    private static function assertConfigured(): void
    {
        if (self::$basePath === null || self::$pincorePath === null) {
            throw new RuntimeException('ApplicationPaths is not configured. Call ApplicationPaths::configure() first.');
        }
    }
}
