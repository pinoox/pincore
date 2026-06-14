<?php

namespace Pinoox\Component\Database;

use Pinoox\Component\Store\Baker\EnvSensitiveConfig;
use Pinoox\Component\Store\Config\Strategy\FileConfigStrategy;
use Pinoox\Portal\Config;
use Pinoox\Support\SystemConfig;

/**
 * Persist platform database connections to Pinker (~database config).
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
                return false;
            }

            $pinker = $strategy->getPinker();
            $overridePath = $pinker->getOverrideFile();
            $overrideBackup = is_string($overridePath) && is_file($overridePath)
                ? file_get_contents($overridePath)
                : null;

            try {
                $pinker->restore();
                $database->restore();

                $root = $database->all();

                if (!is_array($root)) {
                    $root = [];
                }

                $root = DatabaseConfig::normalize($root);

                if ($setDefault) {
                    $root['default'] = $connectionName;
                }

                $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];
                $connections[$connectionName] = array_replace(
                    is_array($connections[$connectionName] ?? null) ? $connections[$connectionName] : [],
                    self::storageConfig($config, $connectionName),
                );
                $root['connections'] = $connections;

                $database->setData($root);

                $pinker->forceOverridePaths(array_filter([
                    'default' => $setDefault ? $connectionName : null,
                    DatabaseConfig::pinkerPathForConnection($connectionName) => DatabaseConfig::pinkerPathForConnection($connectionName),
                ]));

                $storedProfiles = self::storedProfiles($root);

                $pinker->info([
                    'env_sensitive' => 'yes',
                    'env_priority' => EnvSensitiveConfig::envPriorityLabel(),
                    'env_resolution' => EnvSensitiveConfig::resolutionLabel(),
                    'stored_profiles' => implode(',', $storedProfiles),
                ]);

                $database->save();
                SystemConfig::clearCache();

                return true;
            } catch (\Throwable $e) {
                if ($overrideBackup !== null && is_string($overridePath)) {
                    file_put_contents($overridePath, $overrideBackup);
                    SystemConfig::clearCache();
                }

                throw $e;
            }
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
            $pinker->restore();
            $database->restore();

            $root = $database->all();

            if (!is_array($root)) {
                return false;
            }

            $root = DatabaseConfig::normalize($root);
            $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

            if (!isset($connections[$connectionName])) {
                return false;
            }

            $root['default'] = $connectionName;
            $database->setData($root);
            $pinker->forceOverridePaths(['default' => $connectionName]);
            $database->save();
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

    /**
     * @param array<string, mixed> $root
     * @return list<string>
     */
    private static function storedProfiles(array $root): array
    {
        $profiles = ['default'];
        $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

        foreach (array_keys($connections) as $name) {
            $profiles[] = DatabaseConfig::pinkerPathForConnection((string) $name);
        }

        return array_values(array_unique($profiles));
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
     * Pinker stores the logical driver (mariadb stays mariadb); runtime may normalize to mysql.
     *
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
