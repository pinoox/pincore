<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Support\SystemConfig;

class PinxVersion
{
    /**
     * Kernel (pincore package) version — used for minpin and package compatibility.
     *
     * @return array{name: string, code: int|null}
     */
    public static function kernel(): array
    {
        return self::readVersion('pincore');
    }

    /**
     * Platform distribution version from {project}/config/pinoox.config.php.
     *
     * @return array{name: string, code: int|null}
     */
    public static function platform(): array
    {
        return self::readVersion('pinoox');
    }

    /**
     * @return array{name: string, code: int|null}
     * @deprecated Use {@see kernel()} for minpin checks or {@see platform()} for distribution version.
     */
    public static function pinoox(): array
    {
        return self::kernel();
    }

    public static function satisfiesMinpin(int $minpin): bool
    {
        if ($minpin <= 0) {
            return true;
        }

        $version = self::kernel();

        return $version['code'] !== null && $version['code'] >= $minpin;
    }

    public static function minpinError(int $minpin): string
    {
        $version = self::kernel();
        $current = $version['code'] ?? 'unknown';

        return sprintf(
            'This package requires Pinoox kernel version code %d or higher (current: %s).',
            $minpin,
            (string) $current
        );
    }

    /**
     * @return array{name: string, code: int|null}
     */
    private static function readVersion(string $config): array
    {
        $name = '';
        $code = null;

        try {
            $data = SystemConfig::get($config);

            if (is_array($data)) {
                $name = trim((string) ($data['version_name'] ?? ''));
                $rawCode = $data['version_code'] ?? null;

                if ($rawCode !== null && $rawCode !== '') {
                    $code = (int) $rawCode;
                }
            }
        } catch (\Throwable) {
        }

        if ($name === '' && $code === null) {
            $configFile = $config === 'pincore'
                ? SystemConfig::corePath('config/pincore.config.php')
                : SystemConfig::platformPinooxManifestFile();

            if (is_file($configFile)) {
                $loaded = include $configFile;
                if (is_array($loaded)) {
                    $name = trim((string) ($loaded['version_name'] ?? ''));
                    if (isset($loaded['version_code']) && $loaded['version_code'] !== '') {
                        $code = (int) $loaded['version_code'];
                    }
                }
            }
        }

        return [
            'name' => $name,
            'code' => $code,
        ];
    }
}
