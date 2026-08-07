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

namespace Pinoox\Component\Database\Factories;

use Pinoox\Support\PackageContext;

/**
 * Preferred base for app/platform factory files (anonymous or named).
 *
 * @example
 * return new class extends FactoryBase {
 *     protected ?string $model = PostModel::class;
 *     public function definition(): array { return [...]; }
 * };
 */
abstract class FactoryBase extends Factory
{
    public static function usePackage(?string $package): void
    {
        PackageContext::use($package);
    }

    protected function getPackage(): string
    {
        return PackageContext::resolve();
    }
}
