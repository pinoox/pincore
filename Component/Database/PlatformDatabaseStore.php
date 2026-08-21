<?php

namespace Pinoox\Component\Database;

use Pinoox\Component\Store\Config\Strategy\FileConfigStrategy;
use Pinoox\Portal\Config;
use Pinoox\Support\SystemConfig;

/**
 * Persist platform database connections to pinker/stable (simple hand-editable config).
 */
final class PlatformDatabaseStore
{
    /**
     * @param array<string, mixed> $config Normalized runtime connection config
     */
    public static function saveConnection(string $connectionName, array $config, bool $setDefault = false): bool
    {
        $connectionName = self::normalizeConnectionName($connectionName);

        try {
            $database = Config::name('~database');
            $strategy = $database->getStrategy();

            if (!$strategy instanceof FileConfigStrategy) {
                return self::writeFallbackFile($connectionName, [
                    'default' => $connectionName,
                    'connections' => [
                        $connectionName => self::storageConfig($config, $connectionName),
                    ],
                ]);
            }

            $pinker = $strategy->getPinker();
            $stablePath = $pinker->getStableFile();
            $stableBackup = is_string($stablePath) && is_file($stablePath)
                ? file_get_contents($stablePath)
                : null;

            try {
                $existing = $pinker->loadStable() ?? [];
                $connections = is_array($existing['connections'] ?? null) ? $existing['connections'] : [];
                $connections[$connectionName] = array_replace(
                    is_array($connections[$connectionName] ?? null) ? $connections[$connectionName] : [],
                    self::storageConfig($config, $connectionName),
                );

                $payload = $existing;
                $payload['connections'] = $connections;

                if ($setDefault || !isset($payload['default'])) {
                    $payload['default'] = $connectionName;
                }

                // Soft state must not fight durable stable for the same config.
                $pinker->removeOverride();
                $pinker->saveStable($payload);
                SystemConfig::clearCache();

                $stablePath = $pinker->getStableFile();
                if (is_string($stablePath) && is_file($stablePath)) {
                    return true;
                }

                return self::writeFallbackFile($connectionName, $payload);
            } catch (\Throwable $e) {
                if ($stableBackup !== null && is_string($stablePath)) {
                    file_put_contents($stablePath, $stableBackup);
                    SystemConfig::clearCache();
                }

                throw $e;
            }
        } catch (\Throwable) {
            return self::writeFallbackFile($connectionName, [
                'default' => $connectionName,
                'connections' => [
                    $connectionName => self::storageConfig($config, $connectionName),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeFallbackFile(string $connectionName, array $payload): bool
    {
        unset($connectionName);

        try {
            $file = SystemConfig::pinkerStableConfigPath('database');
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }

            $export = var_export($payload, true);

            return @file_put_contents($file, "<?php\n\nreturn {$export};\n") !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setDefault(string $connectionName): bool
    {
        $connectionName = self::normalizeConnectionName($connectionName);

        try {
            $database = Config::name('~database');
            $strategy = $database->getStrategy();

            if (!$strategy instanceof FileConfigStrategy) {
                return false;
            }

            $pinker = $strategy->getPinker();
            $existing = $pinker->loadStable() ?? [];
            $connections = is_array($existing['connections'] ?? null) ? $existing['connections'] : [];

            if (!isset($connections[$connectionName])) {
                $root = self::platformRoot();
                $platformConnections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

                if (!isset($platformConnections[$connectionName])) {
                    return false;
                }

                $connections[$connectionName] = self::storageConfig(
                    is_array($platformConnections[$connectionName]) ? $platformConnections[$connectionName] : [],
                    $connectionName,
                );
            }

            $existing['connections'] = $connections;
            $existing['default'] = $connectionName;
            $pinker->removeOverride();
            $pinker->saveStable($existing);
            SystemConfig::clearCache();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $partial Partial connection fields to merge
     */
    public static function updateConnection(string $connectionName, array $partial): bool
    {
        $connectionName = self::normalizeConnectionName($connectionName);
        $root = self::platformRoot();

        if (!isset($root['connections'][$connectionName])) {
            return false;
        }

        $current = is_array($root['connections'][$connectionName]) ? $root['connections'][$connectionName] : [];
        $merged = array_replace($current, array_filter($partial, static fn ($value) => $value !== null));

        return self::saveConnection($connectionName, DatabaseConfig::normalizeConnectionDriver($merged));
    }

    /**
     * @return array<string, mixed>
     */
    public static function platformRoot(): array
    {
        $root = SystemConfig::get('database');

        return is_array($root) ? DatabaseConfig::normalize($root) : [
            'default' => DatabaseConfig::DEFAULT_CONNECTION,
            'connections' => [],
        ];
    }

    private static function normalizeConnectionName(string $connectionName): string
    {
        $connectionName = strtolower(trim($connectionName));

        if ($connectionName === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $connectionName)) {
            throw new \InvalidArgumentException('Connection name must start with a letter and contain only letters, numbers, and underscores.');
        }

        return $connectionName;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function storageConfig(array $config, string $connectionName): array
    {
        $stored = $config;
        $stored['driver'] = match ($connectionName) {
            'mariadb' => 'mariadb',
            'pgsql' => 'pgsql',
            'sqlsrv' => 'sqlsrv',
            default => (string) ($config['driver'] ?? 'mysql'),
        };

        if ($stored['driver'] === 'mysql' && ($config['driver'] ?? '') === 'mariadb') {
            $stored['driver'] = 'mariadb';
        }

        return $stored;
    }
}
