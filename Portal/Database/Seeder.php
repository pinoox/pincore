<?php

/**
 * ***  *  *     *  ****  ****  *    *
 *   *  *  * *   *  *  *  *  *   *  *
 * ***  *  *  *  *  *  *  *  *    *
 *      *  *   * *  *  *  *  *   *  *
 *      *  *    **  ****  ****  *    *
 *
 * @author   Pinoox
 * @link https://www.pinoox.com
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Portal\Database;

use Pinoox\Component\Database\Seeder\SeederRunner as ObjectPortal1;
use Pinoox\Component\Source\Portal;

/**
 * Run database seeders from migrations, patches, or application code.
 *
 * Seeders are never auto-run on app install — call them explicitly.
 *
 * @method static int run(string|array $name, ?string $package = null)
 * @method static int runAll(?string $package = null)
 * @method static array resolve(?string $name = null, ?string $package = null)
 * @method static bool matchesName(array $seeder, string $name)
 * @method static string normalizeName(string $name)
 * @method static \Pinoox\Component\Database\Seeder\SeederRunner ___()
 *
 * @see \Pinoox\Component\Database\Seeder\SeederRunner
 */
class Seeder extends Portal
{
    public static function __register(): void
    {
        self::__bind(ObjectPortal1::class);
    }

    /**
     * Get the registered name of the component.
     */
    public static function __name(): string
    {
        return 'database.seeder';
    }

    /**
     * Get exclude method names.
     * @return string[]
     */
    public static function __exclude(): array
    {
        return [];
    }

    /**
     * Get method names for callback object.
     * @return string[]
     */
    public static function __callback(): array
    {
        return [];
    }
}
