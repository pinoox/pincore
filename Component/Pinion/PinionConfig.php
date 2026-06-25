<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Pinion\Config as PackageConfig;
use Pinoox\Support\SystemConfig;

final class PinionConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(?array $overrides = null): array
    {
        $config = self::load('pinion');
        $merged = array_merge($config, $overrides ?? []);

        $stagingPath = null;
        if (!isset($merged['storage_path']) || str_starts_with((string) $merged['storage_path'], '~')) {
            $stagingPath = SystemConfig::path('pinion_uploads');
            $merged['storage_path'] = $stagingPath;
        } else {
            $stagingPath = (string) $merged['storage_path'];
        }

        $merged = PinionHostLimits::tune($merged, $stagingPath);

        return PackageConfig::resolve($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadDefaults(): array
    {
        $config = self::load('pinion');

        return is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $name): array
    {
        $data = SystemConfig::get($name);

        return is_array($data) ? $data : [];
    }
}
