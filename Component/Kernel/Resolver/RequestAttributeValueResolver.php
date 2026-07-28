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

namespace Pinoox\Component\Kernel\Resolver;

use Pinoox\Component\Database\Model;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Yields a non-variadic argument's value from the request attributes.
 *
 * Skips Model-typed arguments when the attribute is still a scalar so
 * {@see ModelValueResolver} can load the record by route key.
 */
final class RequestAttributeValueResolver implements ArgumentValueResolverInterface
{
    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        if ($argument->isVariadic()) {
            return false;
        }

        $type = $argument->getType();

        if ($type !== null && (Request::class === $type || is_subclass_of($type, Request::class))) {
            return false;
        }

        if (!$request->attributes->has($argument->getName())) {
            return false;
        }

        $value = $request->attributes->get($argument->getName());

        if (
            is_string($type)
            && class_exists($type)
            && is_subclass_of($type, Model::class)
            && (is_scalar($value) || $value === null)
        ) {
            return false;
        }

        return true;
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();

        if ($type !== null && (Request::class === $type || is_subclass_of($type, Request::class))) {
            return;
        }

        if (!$argument->isVariadic() && $request->attributes->has($argument->getName())) {
            $value = $request->attributes->get($argument->getName());

            if (
                is_string($type)
                && class_exists($type)
                && is_subclass_of($type, Model::class)
                && (is_scalar($value) || $value === null)
            ) {
                return;
            }

            yield $value;
        }
    }
}
