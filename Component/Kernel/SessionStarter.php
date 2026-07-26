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

namespace Pinoox\Component\Kernel;

use Pinoox\Support\SystemConfig;
use Symfony\Component\HttpFoundation\Request;

final class SessionStarter
{
    private static bool $savePathConfigured = false;

    public static function configureSavePath(): void
    {
        if (self::$savePathConfigured) {
            return;
        }

        self::$savePathConfigured = true;

        // Prefer app-owned paths first. Shared hosts often set session.save_path
        // outside open_basedir (e.g. /opt/alt/phpXX/...), which would warn on is_dir().
        foreach (self::candidateSavePaths() as $path) {
            if (!self::isAccessibleDirectory($path)) {
                @mkdir($path, 0775, true);
            }

            if (self::isWritableDirectory($path)) {
                @ini_set('session.save_path', $path);
                return;
            }
        }

        $current = trim((string) ini_get('session.save_path'));

        if ($current !== '' && self::isWritableDirectory($current)) {
            return;
        }
    }

    public static function start(Request $request): bool
    {
        if (PHP_SAPI === 'cli' || !$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();

        if ($session->isStarted()) {
            return true;
        }

        if (\PHP_SESSION_ACTIVE === session_status()) {
            return false;
        }

        if (headers_sent()) {
            return false;
        }

        self::configureSavePath();

        try {
            return $session->start();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Release the session file lock as soon as the response is ready.
     * Prevents parallel requests from blocking on session_start().
     */
    public static function release(Request $request): void
    {
        if (PHP_SAPI === 'cli' || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (!$session->isStarted()) {
            return;
        }

        try {
            $session->save();
        } catch (\Throwable) {
        }
    }

    /**
     * @return string[]
     */
    private static function candidateSavePaths(): array
    {
        $paths = [];
        $configured = SystemConfig::get('session', 'files', '~storage/sessions');

        if (is_string($configured) && trim($configured) !== '') {
            $paths[] = SystemConfig::resolvePath($configured);
        }

        $mampTmp = getenv('TEMP') ?: getenv('TMP') ?: '';

        if ($mampTmp !== '') {
            $paths[] = rtrim(str_replace('\\', '/', $mampTmp), '/') . '/pinoox-sessions';
        }

        $systemTemp = sys_get_temp_dir();

        if ($systemTemp !== '' && self::isPathAllowedByOpenBasedir($systemTemp)) {
            $paths[] = rtrim($systemTemp, '\\/') . DIRECTORY_SEPARATOR . 'pinoox-sessions';
        }

        return array_values(array_unique(array_filter(
            $paths,
            static fn(string $path): bool => self::isPathAllowedByOpenBasedir($path)
        )));
    }

    private static function isWritableDirectory(string $path): bool
    {
        return self::isAccessibleDirectory($path) && @is_writable($path);
    }

    private static function isAccessibleDirectory(string $path): bool
    {
        return self::isPathAllowedByOpenBasedir($path) && @is_dir($path);
    }

    private static function isPathAllowedByOpenBasedir(string $path): bool
    {
        $openBasedir = (string) ini_get('open_basedir');

        if ($openBasedir === '') {
            return true;
        }

        $normalizedPath = self::normalizePath($path);
        $allowedRoots = array_filter(array_map(
            static fn(string $root): string => self::normalizePath($root),
            explode(PATH_SEPARATOR, $openBasedir)
        ));

        foreach ($allowedRoots as $root) {
            if ($normalizedPath === $root || str_starts_with($normalizedPath, rtrim($root, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
