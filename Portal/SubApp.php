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

use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\Response;
use Pinoox\Component\Source\Portal;

/**
 * @method static Response run(string $package, string $mountPath = '/', ?Request $request = NULL, array $options = [])
 * @method static string resolvePackage(string $packageOrPath, array &$options = [])
 * @method static string path(string $package, string $path = '', array $options = [])
 * @method static bool exists(string $package, array $options = [])
 * @method static bool isSubApp()
 * @method static ?string parent()
 * @method static ?string host()
 * @method static bool isSubAppOf(string|array $packages)
 * @method static bool canMount(string $guestPackage, ?string $hostPackage = NULL, array $options = [])
 * @method static bool isSubAppOnly(string $packageName, array $options = [])
 * @method static mixed context(?string $key = NULL, mixed $default = NULL)
 * @method static mixed resolveContext(?string $key = NULL, mixed $default = NULL)
 * @method static mixed rawContext(?string $key = NULL, mixed $default = NULL)
 * @method static string mountPath()
 * @method static string baseUrl()
 * @method static \Pinoox\Component\Package\SubApp ___()
 *
 * @see \Pinoox\Component\Package\SubApp
 */
class SubApp extends Portal
{
    public static function __register(): void
    {
        self::__bind(\Pinoox\Component\Package\SubApp::class);
    }

    public static function __name(): string
    {
        return 'sub_app';
    }
}
