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

use Pinoox\Component\Database\Model;
use Pinoox\Component\Http\Request;
use Pinoox\Portal\Database\DB;

/**
 * Resolve route parameters to Eloquent models using the model's route key.
 */
class ModelResolver extends Resolver
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly ?string $key = null,
    ) {
    }

    public function resolve(mixed $value, string $parameter, Request $request): mixed
    {
        if (!is_subclass_of($this->modelClass, Model::class)) {
            return null;
        }

        if (!DB::hasConnection()) {
            return null;
        }

        if ($value instanceof $this->modelClass) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->modelClass;
        $column = $this->key ?? $class::routeKeyName();

        return $class::query()->where($column, $value)->first();
    }
}
