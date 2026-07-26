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
use Pinoox\Portal\Database\DB;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Yields the Session.
 */
final class ModelValueResolver implements ArgumentValueResolverInterface
{
    /**
     * {@inheritdoc}
     */
    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        if (!DB::hasConnection()) {
            return false;
        }

        $type = $argument->getType();

        return \is_string($type)
            && class_exists($type)
            && is_subclass_of($type, Model::class);
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();
        if (!\is_string($type) || !class_exists($type) || !is_subclass_of($type, Model::class)) {
            return;
        }

        yield new $type();
    }
}