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
use Pinoox\Component\Flow\Flow;
use Pinoox\Component\Http\Request;
use Pinoox\Component\RouteResolver\ResolverManager;
use Pinoox\Portal\RouteResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Resolve registered route parameters into objects before the controller runs.
 *
 * Alias: resolve
 * Or:    ResolveFlow::class / new ResolveFlow()
 */
class ResolveFlow extends Flow
{
    public function __construct(
        ?RequestEvent $requestEvent = null,
        private readonly ?ResolverManager $manager = null,
    ) {
        parent::__construct($requestEvent);
    }

    protected function handle(Request $request, Closure $next)
    {
        $response = $this->manager()->resolve($request);

        if ($response !== null) {
            return $response;
        }

        return $next($request);
    }

    private function manager(): ResolverManager
    {
        return $this->manager ?? RouteResolver::___();
    }
}
