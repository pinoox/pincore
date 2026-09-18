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


namespace Pinoox\Model\Scope;


use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AppScope implements Scope
{
    /** @param Closure(): list<string> $resolver */
    public function __construct(private readonly Closure $resolver)
    {
    }

    /**
     * @param Closure(): list<string> $resolver
     */
    public static function for(Closure $resolver): self
    {
        return new self($resolver);
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        $apps = ($this->resolver)();

        if ($apps === []) {
            return;
        }

        if (count($apps) === 1) {
            $builder->where('app', $apps[0]);

            return;
        }

        $builder->whereIn('app', $apps);
    }
}
