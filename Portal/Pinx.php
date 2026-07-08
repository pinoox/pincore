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

use Pinoox\Component\Package\Pinx\PinxBuilder;
use Pinoox\Component\Package\Pinx\PinxInstallResult;
use Pinoox\Component\Package\Pinx\PinxInstaller;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Package\Pinx\PinxReader;
use Pinoox\Component\Package\Pinx\PinxService;
use Pinoox\Component\Package\Pinx\PinxUninstallResult;
use Pinoox\Component\Package\Pinx\PinxUninstaller;
use Pinoox\Component\Source\Portal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\SystemConfig;

/**
 * @method static PinxInstallResult install(string $packagePath, array $options = [])
 * @method static PinxUninstallResult uninstallApp(string $package, array $options = [])
 * @method static PinxUninstallResult uninstallTheme(string $package, string $themeName, array $options = [])
 * @method static PinxManifest manifest(string $packagePath)
 * @method static mixed withReader(string $packagePath, callable $callback)
 * @method static string resolveMode(PinxManifest $manifest, bool $force = false)
 * @method static PinxInstaller installer()
 * @method static PinxUninstaller uninstaller()
 * @method static PinxBuilder builder()
 * @method static PlatformBuilder platformBuilder()
 * @method static string tmpPath()
 * @method static \Pinoox\Component\Package\Engine\AppEngine engine()
 * @method static PinxService ___()
 *
 * @see \Pinoox\Component\Package\Pinx\PinxService
 */
class Pinx extends Portal
{
    public static function __register(): void
    {
        self::__bind(PinxService::class)->setArguments([
            AppEngine::__ref(),
            SystemConfig::path('wizard_tmp'),
        ]);
    }

    public static function __name(): string
    {
        return 'pinx';
    }

    /**
     * @return string[]
     */
    public static function __exclude(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function __callback(): array
    {
        return [];
    }
}
