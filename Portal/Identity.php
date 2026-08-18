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

namespace Pinoox\Portal;

use Pinoox\Component\Source\Portal;

/**
 * Stable per-install Pinoox ID.
 *
 * @method static array ensure()
 * @method static string id()
 * @method static string|null createdAt()
 * @method static string file()
 * @method static \Pinoox\Component\Identity\Identity ___()
 *
 * @see \Pinoox\Component\Identity\Identity
 */
class Identity extends Portal
{
    public static function __register(): void
    {
        self::__bind(\Pinoox\Component\Identity\Identity::class);
    }

    public static function __name(): string
    {
        return 'identity';
    }

    public static function __callback(): array
    {
        return [];
    }
}
