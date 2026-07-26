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

use Pinoox\Component\Http\FormRequest;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Yields the same instance as the request object passed along.
 */
final class FormRequestValueResolver implements ArgumentValueResolverInterface
{
    /**
     * {@inheritdoc}
     */
    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        $class = $argument->getType();

        return \is_string($class)
            && class_exists($class)
            && is_subclass_of($class, FormRequest::class);
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();

        // Symfony ArgumentResolver always calls resolve(); skip builtins / non-FormRequest types.
        if (!\is_string($type) || !class_exists($type) || !is_subclass_of($type, FormRequest::class)) {
            return;
        }

        /** @var FormRequest $formRequest */
        $formRequest = new $type($request);
        $formRequest->__resolve();
        yield $formRequest;
    }
}
