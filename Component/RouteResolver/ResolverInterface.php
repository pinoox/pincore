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

use Pinoox\Component\Http\Request;

interface ResolverInterface
{
    /**
     * Resolve a raw route parameter value into an object (or other value).
     *
     * Return null when the resource cannot be found.
     */
    public function resolve(mixed $value, string $parameter, Request $request): mixed;
}
