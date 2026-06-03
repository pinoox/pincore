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

namespace Pinoox\Component\Foundation;

use Pinoox\Portal\App\AppProvider;

final class Application
{
    public static function configure(string $basePath, ?string $pincorePath = null): void
    {
        ApplicationPaths::configure($basePath, $pincorePath);

        if (!defined('PINOOX_CORE_PATH')) {
            define('PINOOX_CORE_PATH', ApplicationPaths::pincorePath());
        }
    }

    public static function boot(): void
    {
        AppProvider::boot();
    }
}
