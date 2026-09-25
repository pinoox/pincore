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

use Closure;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Flow\Flow;
use Pinoox\Component\Router\Route;
use Pinoox\Portal\Auth;

abstract class AuthFlow extends Flow
{
    final protected function handle(Request $request, Closure $next)
    {
        $route = $request->attributes->get('_router');

        if ($this->validate($request, $route) && $this->checkExcludeRequestUri($request, $route) && $this->checkIncludeRequestUri($request, $route)) {
            if (Auth::guest()) {
                $exit = $this->unauthenticated($request, $route);
                if ($exit !== true && !is_null($exit)) {
                    return $exit;
                }
            } elseif (!$this->authorize($request, $route)) {
                $exit = $this->forbidden($request, $route);
                if ($exit !== true && !is_null($exit)) {
                    return $exit;
                }
            }
        }

        return $next($request);
    }

    private function checkExcludeRequestUri(Request $request, ?Route $route): bool
    {
        $excludeItems = $this->exclude($request, $route);
        if (is_array($excludeItems)) {
            foreach ($excludeItems as $excludeItem) {
                if (str_starts_with($request->getRequestUri(), $excludeItem)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function checkIncludeRequestUri(Request $request, ?Route $route): bool
    {
        $includeItems = $this->include($request, $route);
        if (is_array($includeItems)) {
            foreach ($includeItems as $includeItem) {
                if (str_starts_with($request->getRequestUri(), $includeItem)) {
                    return true;
                }
            }

            return false;
        }
        return true;
    }

    protected function exclude(Request $request, ?Route $route): ?array
    {
        return null;
    }

    protected function include(Request $request, ?Route $route): ?array
    {
        return null;
    }

    protected function validate(Request $request, $route): bool
    {
        return true;
    }

    /**
     * Check if the authenticated user has access to the requested route/resource.
     */
    protected function authorize(Request $request, ?Route $route): bool
    {
        return true;
    }

    /**
     * Handle unauthenticated guest access (401 / redirect).
     */
    protected function unauthenticated(Request $request, ?Route $route)
    {
        if ($route instanceof Route) {
            return $this->exit($request, $route);
        }

        return $this->defaultUnauthenticated($request);
    }

    /**
     * Handle forbidden access for authenticated users failing authorize() (403).
     */
    protected function forbidden(Request $request, ?Route $route)
    {
        $message = 'Access denied!';

        if ($this->wantsJson($request)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => $message,
            ], 403);
        }

        return response($message, 403);
    }

    /**
     * Backward-compatible exit hook.
     */
    protected function exit(Request $request, Route $route)
    {
        return $this->defaultUnauthenticated($request);
    }

    protected function defaultUnauthenticated(Request $request)
    {
        $message = 'Unauthorized!';

        if ($this->wantsJson($request)) {
            return response()->json([
                'status' => 'error',
                'code' => 401,
                'message' => $message,
            ], 401);
        }

        return response($message, 401);
    }

    protected function wantsJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            || str_contains(strtolower($request->headers->get('Accept', '')), 'json');
    }
}