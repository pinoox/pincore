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

namespace Pinoox\Flow;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;

class AccessFlow extends AuthFlow
{
    /**
     * Determine if the user is authorized to access the route.
     * Override this method in child classes to implement custom role/permission checks.
     */
    protected function authorize(Request $request, ?Route $route): bool
    {
        return true;
    }

    /**
     * Fallback exit hook delegating to unauthenticated().
     */
    protected function exit(Request $request, Route $route)
    {
        return $this->unauthenticated($request, $route);
    }
}
