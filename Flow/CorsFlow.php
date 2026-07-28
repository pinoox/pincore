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
use Pinoox\Component\Cors\CorsManager;
use Pinoox\Component\Flow\Flow;
use Pinoox\Component\Http\Request;
use Pinoox\Portal\Cors;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Apply a named CORS policy on HTTP routes.
 *
 * Alias: cors:api  →  CorsFlow::for('api')
 * Or:    new CorsFlow('api') / CorsFlow::for() for default
 */
class CorsFlow extends Flow
{
    private ?string $policy;

    private ?CorsManager $cors;

    /**
     * Compatible with FlowManager (`new $class($requestEvent)`) and
     * parameterized aliases (`cors:api` → `new CorsFlow('api', $event)`).
     */
    public function __construct(
        string|RequestEvent|null $policyOrEvent = null,
        ?RequestEvent $requestEvent = null,
        ?CorsManager $cors = null,
    ) {
        $this->cors = $cors;

        if ($policyOrEvent instanceof RequestEvent) {
            $this->policy = null;
            parent::__construct($policyOrEvent);

            return;
        }

        $this->policy = $policyOrEvent;
        parent::__construct($requestEvent);
    }

    public static function for(?string $policy = null, ?CorsManager $cors = null): self
    {
        return new self($policy, null, $cors);
    }

    public function policy(): ?string
    {
        return $this->policy;
    }

    protected function handle(Request $request, Closure $next)
    {
        $manager = $this->manager();
        $name = $this->policy;

        if ($manager->isPreflight($request)) {
            return $manager->handlePreflight($request, $name);
        }

        $response = $next($request);

        if ($response instanceof Response) {
            return $manager->apply($response, $request, $name);
        }

        return $response;
    }

    private function manager(): CorsManager
    {
        return $this->cors ?? Cors::___();
    }
}
