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

namespace Pinoox\Component\RouteResolver;

use Closure;
use Pinoox\Component\Http\Request;

/**
 * Wrap a callable as a route parameter resolver.
 */
class CallbackResolver extends Resolver
{
    public function __construct(
        private readonly Closure $callback,
    ) {
    }

    public function resolve(mixed $value, string $parameter, Request $request): mixed
    {
        return ($this->callback)($value, $parameter, $request);
    }
}
