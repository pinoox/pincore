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

use Pinoox\Component\Package\Reference\NameReference;
use Pinoox\Component\Package\Reference\ReferenceInterface;
use Pinoox\Component\Source\Portal;
use Pinoox\Component\Store\Config\Config as ObjectPortal1;
use Pinoox\Component\Store\Config\Strategy\FileConfigStrategy;
use Pinoox\Support\SystemConfig;
use Pinoox\Support\SystemApp;

/**
 * @method static \Pinoox\Component\Store\Config\Config create(\Pinoox\Component\Store\Config\Strategy\ConfigStrategyInterface $strategy)
 * @method static \Pinoox\Component\Store\Config\Strategy\FileConfigStrategy ___strategy()
 * @method static \Pinoox\Component\Store\Config\Config ___()
 *
 * @see \Pinoox\Component\Store\Config\Config
 */
class Config extends Portal
{
    const ext = 'config.php';

    public static function __register(): void
    {
        self::__bind(FileConfigStrategy::class, 'strategy')->setArguments([
            Pinker::__ref(),
        ]);

        self::__bind(ObjectPortal1::class)->setArguments([
            self::__ref('strategy')
        ]);
    }

    /**
     * Set file for pinoox baker
     *
     * @param string|ReferenceInterface $fileName
     * @return ObjectPortal1
     */
    public static function name(string|ReferenceInterface $fileName): ObjectPortal1
    {
        return self::initFileConfig($fileName);
    }

    public static function file(string $file): ObjectPortal1
    {
        $pinker = Pinker::create($file, $file);
        return self::create(new FileConfigStrategy($pinker));
    }

    private static function initFileConfig(string|ReferenceInterface $fileName): ObjectPortal1
    {
        if (self::isPinooxConfigReference($fileName)) {
            return self::initPinooxConfig();
        }

        if (self::isPincoreConfigReference($fileName)) {
            return self::initPincoreConfig();
        }

        if (is_string($fileName)) {
        $fileName = $fileName . '.' . self::ext;
        }

        $folder = SystemConfig::rawPath('app_config', 'config');
        $ref = Path::prefixReference($fileName, $folder);

        if ($ref->getPackageName() === '~') {
            $value = $ref->getValue();

            foreach ([SystemApp::PATH_ALIAS, SystemApp::LEGACY_PATH_ALIAS] as $alias) {
                $prefix = $folder . '/' . $alias . '/';

                if (is_string($value) && str_starts_with($value, $prefix)) {
                    $value = $folder . '/' . substr($value, strlen($prefix));
                    break;
                }
            }

            $ref = NameReference::create(SystemApp::PACKAGE, $value);
        }

        $pinker = Pinker::file($ref);
        return self::create(new FileConfigStrategy($pinker));
    }

    private static function isPinooxConfigReference(string|ReferenceInterface $fileName): bool
    {
        if ($fileName instanceof ReferenceInterface) {
            $value = (string) $fileName->getValue();

            return str_ends_with($value, 'pinoox.config.php')
                || str_ends_with($value, 'pinoox');
        }

        return preg_match('#~pinoox(\.config\.php)?$#', $fileName) === 1;
    }

    private static function initPinooxConfig(): ObjectPortal1
    {
        $templateFile = SystemConfig::platformPinooxTemplateFile();
        $bakedFile = Pinker::bakedFileFromSource($templateFile);
        $pinker = Pinker::create(is_file($templateFile) ? $templateFile : '', $bakedFile);
        $strategy = new FileConfigStrategy($pinker);

        $manifestFile = SystemConfig::platformPinooxManifestFile();

        if (is_file($manifestFile)) {
            $manifest = require $manifestFile;

            if (is_array($manifest) && $manifest !== []) {
                $strategy->merge($manifest);
            }
        }

        return self::create($strategy);
    }

    private static function isPincoreConfigReference(string|ReferenceInterface $fileName): bool
    {
        if ($fileName instanceof ReferenceInterface) {
            $value = (string) $fileName->getValue();

            return str_ends_with($value, 'pincore.config.php')
                || str_ends_with($value, 'pincore');
        }

        return preg_match('#~pincore(\.config\.php)?$#', $fileName) === 1;
    }

    private static function initPincoreConfig(): ObjectPortal1
    {
        $mainFile = SystemConfig::corePath('config/pincore.config.php');
        $bakedFile = Pinker::bakedFileFromSource($mainFile);
        $pinker = Pinker::create(is_file($mainFile) ? $mainFile : '', $bakedFile);

        return self::create(new FileConfigStrategy($pinker));
    }

    /**
     * Get the registered name of the component.
     * @return string
     */
    public static function __name(): string
    {
        return 'config';
    }

    /**
     * Get include method names .
     * @return string[]
     */
    public static function __include(): array
    {
        return ['name', 'create'];
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

