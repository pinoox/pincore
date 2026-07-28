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

namespace Pinoox\Component\Router;

final class RouteMethod
{

    public const HEAD = 'HEAD';

    public const GET = 'GET';

    public const POST = 'POST';

    public const PUT = 'PUT';

    public const PATCH = 'PATCH';

    public const DELETE = 'DELETE';

    public const QUERY = 'QUERY';

    public const OPTIONS = 'OPTIONS';

    public const PURGE = 'PURGE';

    public const TRACE = 'TRACE';

    public const CONNECT = 'CONNECT';

    /**
     * @var string[]
     */
    public const METHODS = [
        self::HEAD,
        self::GET,
        self::POST,
        self::PUT,
        self::PATCH,
        self::DELETE,
        self::QUERY,
        self::OPTIONS,
        self::PURGE,
        self::TRACE,
        self::CONNECT,
    ];

    /**
     * Aliases that register a route for every HTTP method (Symfony: empty methods list).
     *
     * @var list<string>
     */
    public const ANY_ALIASES = ['ANY', 'ALL', '*'];

    /**
     * Check HTTP method valid
     *
     * @param string $method
     * @return bool
     */
    public static function valid(string $method): bool
    {
        $method = strtoupper($method);

        return in_array($method, self::METHODS, true);
    }

    /**
     * Normalize route methods. An empty result matches any HTTP method.
     *
     * @param array<string>|string $methods
     * @return list<string>
     */
    public static function normalize(array|string $methods): array
    {
        if (is_string($methods)) {
            $methods = [$methods];
        }

        $normalized = [];

        foreach ($methods as $method) {
            $method = strtoupper((string) $method);

            if (in_array($method, self::ANY_ALIASES, true)) {
                return self::METHODS;
            }

            if ($method !== '') {
                $normalized[] = $method;
            }
        }

        return array_values(array_unique($normalized));
    }
}