<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Component\File\FileConfig;

final class StorageContext
{
    public const STAGING_REFERENCE = '~pinion/assembled';

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function mergeDefaults(array $meta, array $defaults = []): array
    {
        $configDefaults = self::configDefaults();
        $merged = array_merge($configDefaults, $defaults, $meta);

        foreach (['storage', 'record'] as $flag) {
            if (isset($merged[$flag])) {
                $merged[$flag] = filter_var($merged[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (!isset($merged['disk'])) {
            $merged['disk'] = FileConfig::resolve()['disk'];
        }

        if (!isset($merged['access'])) {
            $merged['access'] = FileConfig::resolve()['default_access'];
        }

        if (!isset($merged['package']) || $merged['package'] === '') {
            $merged['package'] = FileConfig::resolve()['package'];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $defaults
     */
    public static function usesStorage(array $meta, array $defaults = []): bool
    {
        $meta = self::mergeDefaults($meta, $defaults);

        if (array_key_exists('storage', $meta) && $meta['storage'] !== null) {
            return (bool) $meta['storage'];
        }

        $mode = (string) ($meta['mode'] ?? 'auto');

        return match ($mode) {
            'local' => false,
            'storage' => true,
            default => (string) ($meta['disk'] ?? 'local') !== 'local',
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function storageDestination(array $meta): string
    {
        return (string) ($meta['storage_destination'] ?? $meta['destination'] ?? 'uploads');
    }

    /**
     * @return array<string, mixed>
     */
    private static function configDefaults(): array
    {
        $config = PinionConfig::loadDefaults();

        return is_array($config) ? $config : [];
    }
}
