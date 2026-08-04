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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inject Eloquent models from route attributes (by route key).
 */
final class ModelValueResolver implements ArgumentValueResolverInterface
{
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

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();
        if (!\is_string($type) || !class_exists($type) || !is_subclass_of($type, Model::class)) {
            return;
        }

        if (!DB::hasConnection()) {
            return;
        }

        $name = $argument->getName();
        $value = $request->attributes->get($name);

        if ($value instanceof $type) {
            yield $value;

            return;
        }

        if ($value === null || $value === '') {
            if ($argument->isNullable()) {
                yield null;
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        /** @var class-string<Model> $type */
        $model = $type::query()->where($type::routeKeyName(), $value)->first();

        if ($model === null) {
            if ($argument->isNullable()) {
                yield null;

                return;
            }

            throw new NotFoundHttpException(sprintf(
                'No query results for model [%s] with route key [%s].',
                $type,
                $value,
            ));
        }

        yield $model;
    }
}
