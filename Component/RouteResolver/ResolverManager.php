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
use InvalidArgumentException;
use Pinoox\Component\Database\Model;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Registry of named route parameter resolvers.
 */
class ResolverManager
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    /**
     * Register (or replace) a parameter binding.
     *
     * @param class-string|callable|ResolverInterface $resolver
     */
    public function define(string $parameter, mixed $resolver): Binding
    {
        return $this->bind($parameter, $resolver);
    }

    /**
     * @param class-string|callable|ResolverInterface $resolver
     */
    public function bind(string $parameter, mixed $resolver): Binding
    {
        $binding = new Binding($parameter, $resolver, $this);
        $this->register($binding);

        return $binding;
    }

    public function register(Binding $binding): void
    {
        $this->bindings[$binding->parameter()] = $binding;
    }

    public function has(string $parameter): bool
    {
        return isset($this->bindings[$parameter]);
    }

    public function get(string $parameter): ?Binding
    {
        return $this->bindings[$parameter] ?? null;
    }

    /**
     * @return array<string, Binding>
     */
    public function all(): array
    {
        return $this->bindings;
    }

    public function forget(string $parameter): void
    {
        unset($this->bindings[$parameter]);
    }

    public function flush(): void
    {
        $this->bindings = [];
    }

    /**
     * Resolve a single parameter. Returns null when not found.
     */
    public function resolveParameter(string $parameter, mixed $value, Request $request): mixed
    {
        $binding = $this->bindings[$parameter] ?? null;

        if ($binding === null) {
            return $value;
        }

        $resolver = $this->makeResolver($binding->resolver());

        return $resolver->resolve($value, $parameter, $request);
    }

    /**
     * Resolve all bound attributes on the request in place.
     *
     * @return Response|null A response when a missing handler returns one, else null
     */
    public function resolve(Request $request): ?Response
    {
        foreach ($this->bindings as $parameter => $binding) {
            if (!$request->attributes->has($parameter)) {
                continue;
            }

            $current = $request->attributes->get($parameter);

            // Already resolved to an object/array by a previous flow.
            if (!is_scalar($current) && $current !== null) {
                continue;
            }

            $resolved = $this->resolveParameter($parameter, $current, $request);

            if ($resolved === null) {
                $response = $this->handleMissing($binding, $parameter, $current, $request);
                if ($response instanceof Response) {
                    return $response;
                }

                throw new NotFoundHttpException(sprintf(
                    'No query results for route parameter [%s].',
                    $parameter,
                ));
            }

            $request->attributes->set($parameter, $resolved);
        }

        return null;
    }

    private function handleMissing(
        Binding $binding,
        string $parameter,
        mixed $value,
        Request $request,
    ): mixed {
        $callback = $binding->missingCallback();

        if ($callback === null) {
            return null;
        }

        return $callback($value, $parameter, $request);
    }

    private function makeResolver(mixed $resolver): ResolverInterface
    {
        if ($resolver instanceof ResolverInterface) {
            return $resolver;
        }

        if ($resolver instanceof Closure || is_callable($resolver)) {
            return new CallbackResolver($resolver(...));
        }

        if (is_string($resolver) && class_exists($resolver)) {
            if (is_subclass_of($resolver, Model::class)) {
                return new ModelResolver($resolver);
            }

            if (is_subclass_of($resolver, ResolverInterface::class)) {
                return new $resolver();
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Invalid route resolver [%s]. Expected model class, ResolverInterface, or callable.',
            is_string($resolver) ? $resolver : get_debug_type($resolver),
        ));
    }
}
