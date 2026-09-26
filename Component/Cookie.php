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

namespace Pinoox\Component;

class Cookie
{
    /**
     * Set new Cookie.
     *
     * @param string $key
     * @param string $value
     * @param int $time give in seconds, default time is 86400 seconds (1 day).
     * @param string|null $path
     * @param string|null $domain
     * @param bool $https
     * @param bool $httpOnly
     * @return void
     */
    public static function set($key, $value, $time = 86400, $path = "/", $domain = null, $https = null, $httpOnly = true)
    {
        $https ??= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie($key, $value, [
            'expires' => time() + $time,
            'path' => $path ?? '/',
            'domain' => $domain ?? '',
            'secure' => (bool) $https,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$key] = $value;
    }

    /**
     * Get Cookie.
     *
     * @param string|null $key
     * @return bool
     */
    public static function get($key = null)
    {
        if (!empty($key))
            return isset($_COOKIE[$key]) ? $_COOKIE[$key] : null;
        else
            return $_COOKIE;
    }

    /**
     * Destroy Cookie.
     *
     * if you do not set key that destroy all Cookies also you can destroy specific with key
     *
     * @param string|null $key
     * @return void
     */
    public static function destroy($key = "")
    {
        if (isset($_COOKIE[$key])) {
            setcookie($key, "", time() - 3600, "/");
            return;
        }
        if (count($_COOKIE) > 0) {
            foreach ($_COOKIE as $key => $value) {
                setcookie($key, "", time() - 3600, "/");
            }
        }
    }
}