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

namespace Pinoox\Component\Flow;

use Pinoox\Component\Helpers\Str;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpFoundation\Request as RequestSymfony;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class FlowManager
{
    /**
     * @var FlowInterface[]|string[] $flows
     */
    private array $flows;
    private array $alias = [];
    private RequestEvent $requestEvent;

    public function __construct(array $flows = [], array $alias = [], ?RequestEvent $requestEvent = null)
    {
        $this->flows = $flows;
        $this->alias = $alias;
        if ($requestEvent !== null) {
            $this->setRequestEvent($requestEvent);
        }
    }

    private function handleRow(string|object $flow, Request|RequestSymfony $request, \Closure $next)
    {
        $parameter = null;

        if (is_string($flow) && str_contains($flow, ':') && !str_contains($flow, '\\')) {
            [$flow, $parameter] = explode(':', $flow, 2);
        }

        if ($flow instanceof FlowInterface) {
            return function ($request) use ($flow, $next) {
                return $flow->response($request, $next);
            };
        }

        if (!is_string($flow)) {
            return $next;
        }

        $alias = $this->getAliasNestedValue($flow);

        if ($alias !== null && $alias !== '') {
            if ($alias instanceof FlowInterface) {
                return function ($request) use ($alias, $next) {
                    return $alias->response($request, $next);
                };
            }

            if (is_array($alias)) {
                foreach ($alias as $value) {
                    $next = $this->handleRow($value, $request, $next);
                }

                return $next;
            }

            if (is_string($alias)) {
                $instance = $this->makeFlow($alias, $parameter);
                if ($instance instanceof FlowInterface) {
                    return function ($request) use ($instance, $next) {
                        return $instance->response($request, $next);
                    };
                }
            }

            return $next;
        }

        $instance = $this->makeFlow($flow, $parameter);
        if ($instance instanceof FlowInterface) {
            return function ($request) use ($instance, $next) {
                return $instance->response($request, $next);
            };
        }

        return $next;
    }

    private function makeFlow(string $class, ?string $parameter = null): object
    {
        $event = $this->requestEvent ?? null;

        if ($parameter !== null) {
            return new $class($parameter, $event);
        }

        return new $class($event);
    }

    public function handle(Request|RequestSymfony $request, \Closure $next)
    {
        foreach ($this->getFlows() as $flow) {
            $next = $this->handleRow($flow, $request, $next);
        }

        return $next($request);
    }

    /**
     * @return array
     */
    public function getFlows(): array
    {
        $filters = [];
        $filteredFlows = [];

        foreach ($this->flows as $flow) {
            if (is_string($flow) && Str::firstHas($flow, '!')) {
                $filters[] = Str::firstDelete($flow, '!');
            } else {
                $filteredFlows[] = $flow;
            }
        }

        return array_values(array_filter(
            $filteredFlows,
            static fn ($flow) => !is_string($flow) || !in_array($flow, $filters, true),
        ));
    }

    /**
     * @param array $flows
     */
    public function setFlows(array $flows): void
    {
        $this->flows = $flows;
    }

    public function addFlow(string|FlowInterface $flow): void
    {
        $this->flows[] = $flow;
    }

    public function addFlows(array $flows): void
    {
        $this->flows = array_unique(array_merge($flows, $this->flows));
    }

    /**
     * @return RequestEvent
     */
    public function getRequestEvent(): RequestEvent
    {
        return $this->requestEvent;
    }

    /**
     * @param RequestEvent $requestEvent
     */
    public function setRequestEvent(RequestEvent $requestEvent): void
    {
        $this->requestEvent = $requestEvent;
    }

    /**
     * @return array
     */
    public function getAlias(): array
    {
        return $this->alias;
    }

    /**
     * @param array $alias
     */
    public function setAlias(array $alias): void
    {
        $this->alias = $alias;
    }

    public function addAliases(array $aliases): void
    {
        $this->alias = array_merge($this->alias, $aliases);
    }

    public function getAliasNestedValue(string $key)
    {
        $keys = explode('.', $key);
        $value = $this->alias;

        foreach ($keys as $nestedKey) {
            if (isset($value[$nestedKey])) {
                $value = $value[$nestedKey];
            } else {
                return null;
            }
        }

        return $value;
    }
}
