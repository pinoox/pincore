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
 * Fluent registration handle returned by Route::resolve() / ResolverManager::bind().
 */
class Binding
{
    private ?Closure $missing = null;

    public function __construct(
        private readonly string $parameter,
        private mixed $resolver,
        private readonly ResolverManager $manager,
    ) {
    }

    public function parameter(): string
    {
        return $this->parameter;
    }

    public function resolver(): mixed
    {
        return $this->resolver;
    }

    public function missing(?callable $callback): self
    {
        $this->missing = $callback !== null ? $callback(...) : null;
        $this->manager->register($this);

        return $this;
    }

    public function missingCallback(): ?Closure
    {
        return $this->missing;
    }

    /**
     * Override the resolver target (model class, callable, or ResolverInterface).
     */
    public function using(mixed $resolver): self
    {
        $this->resolver = $resolver;
        $this->manager->register($this);

        return $this;
    }
}
