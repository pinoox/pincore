<?php

namespace Pinoox\Component\Database;

use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Support\SystemConfig;

/**
 * database config: default connection + named connections.
 *
 * Legacy mode profiles (development/production/…) are normalized on read.
 */
final class DatabaseConfig
{
    public const DEFAULT_CONNECTION = 'mysql';

    public const TEST_CONNECTION = 'sqlite';

    public const AUTO_CONNECTION = 'auto';

    public const DEVDB_CONNECTION = 'devdb';

    /**
     * Active connection name (from DB_CONNECTION or config default).
     *
     * @throws \InvalidArgumentException when the connection is not defined in config
     */
    public static function connectionName(): string
    {
        $requested = self::requestedConnectionName();
        $root = SystemConfig::get('database');

        if (!is_array($root)) {
            throw new \InvalidArgumentException('Database config is invalid.');
        }

        $requested = self::resolveConnectionName(self::normalize($root), $requested, true);

        self::connectionConfig(self::normalize($root), $requested, true);

        return $requested;
    }

    /**
     * Raw connection name from env / APP_ENV heuristics (may be undefined in config).
     */
    public static function requestedConnectionName(): string
    {
        $fromEnv = SystemConfig::env('DB_CONNECTION');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::effectiveConnectionName($fromEnv);
        }

        $appEnv = SystemConfig::env('APP_ENV');

        if (!is_string($appEnv) || $appEnv === '') {
            $appEnv = RuntimeMode::fromEnv();
        } else {
            $appEnv = RuntimeMode::normalize($appEnv);
        }

        if (in_array($appEnv, [RuntimeMode::TEST, RuntimeMode::DEVELOPMENT], true)) {
            return self::isLocalRuntime() ? self::DEVDB_CONNECTION : self::DEFAULT_CONNECTION;
        }

        $root = SystemConfig::get('database');

        if (is_array($root)) {
            $root = self::normalize($root);
            $default = (string) ($root['default'] ?? '');

            if ($default !== '') {
                return self::effectiveConnectionName($default);
            }
        }

        return self::DEFAULT_CONNECTION;
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    public static function normalize(array $root): array
    {
        if (isset($root['connections']) && is_array($root['connections'])) {
            $root['default'] = self::normalizeDefaultKey((string) ($root['default'] ?? self::DEFAULT_CONNECTION));

            return self::mergeLegacyProfileKeys($root);
        }

        return self::fromLegacyProfiles($root);
    }

    /**
     * @param array<string, mixed> $root Normalized config root
     * @return array<string, mixed>
     */
    public static function connectionConfig(array $root, ?string $connection = null, bool $forConnection = true): array
    {
        $root = self::normalize($root);
        $connection = self::resolveConnectionName($root, $connection ?? self::requestedConnectionName(), $forConnection);
        $connections = $root['connections'] ?? [];

        if (isset($connections[$connection]) && is_array($connections[$connection])) {
            return self::normalizeConnectionDriver($connections[$connection], $forConnection);
        }

        throw new \InvalidArgumentException('Database connection "' . $connection . '" is not defined.');
    }

    /** @return list<string> */
    public static function supportedConnections(): array
    {
        $root = SystemConfig::get('database');

        if (!is_array($root)) {
            return [self::DEFAULT_CONNECTION, self::TEST_CONNECTION];
        }

        $root = self::normalize($root);
        $names = array_keys($root['connections'] ?? []);

        return $names !== [] ? $names : [self::DEFAULT_CONNECTION, self::TEST_CONNECTION, self::DEVDB_CONNECTION];
    }

    /**
     * Illuminate 10 has no native mariadb connector; MariaDB uses the MySQL protocol.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalizeConnectionDriver(array $config, bool $forConnection = true): array
    {
        if (($config['driver'] ?? null) === 'mariadb') {
            $config['driver'] = 'mysql';
        }

        if (($config['driver'] ?? null) === self::DEVDB_CONNECTION) {
            if (!self::isLocalRuntime()) {
                if (!$forConnection) {
                    return self::describeDevDbConnection($config);
                }

                throw new \RuntimeException('Pinoox DevDB can only be used when APP_ENV=local or APP_ENV=development.');
            }

            return self::normalizeDevDbConnection($config);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function describeDevDbConnection(array $config): array
    {
        $path = self::devDbPath($config);

        return array_replace($config, [
            'driver' => self::DEVDB_CONNECTION,
            'database' => (string) ($config['database'] ?? self::DEVDB_CONNECTION),
            'host' => '—',
            'prefix' => (string) ($config['prefix'] ?? DatabaseManager::DEFAULT_CORE_TABLE_PREFIX),
            'devdb' => true,
            'devdb_path' => $path,
            'devdb_unavailable' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalizeDevDbConnection(array $config): array
    {
        $engine = strtolower(trim((string) ($config['engine'] ?? SystemConfig::env('DEVDB_ENGINE', 'auto'))));
        $engine = $engine !== '' ? $engine : 'auto';

        if (in_array($engine, ['auto', 'sqlite'], true) && extension_loaded('pdo_sqlite')) {
            $path = self::devDbPath($config);
            $sqliteDatabase = (string) ($config['sqlite_database'] ?? SystemConfig::env('DEVDB_SQLITE_DATABASE', ''));
            if ($sqliteDatabase === '') {
                $sqliteDatabase = $path . '/devdb.sqlite';
            }

            $dir = dirname($sqliteDatabase);
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_file($sqliteDatabase)) {
                @touch($sqliteDatabase);
            }

            return [
                'driver' => 'sqlite',
                'database' => $sqliteDatabase,
                'prefix' => (string) ($config['prefix'] ?? DatabaseManager::DEFAULT_CORE_TABLE_PREFIX),
                'foreign_key_constraints' => true,
                'devdb' => true,
                'devdb_engine' => 'sqlite',
                'devdb_path' => $path,
            ];
        }

        $config['engine'] = 'json';
        $config['path'] = self::devDbPath($config);
        $config['devdb'] = true;
        $config['devdb_engine'] = 'json';

        return $config;
    }

    public static function isDevDb(): bool
    {
        try {
            return self::connectionName() === self::DEVDB_CONNECTION;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public static function pinkerStoredPaths(): array
    {
        return [self::pinkerPathForConnection(self::DEFAULT_CONNECTION)];
    }

    public static function pinkerPathForConnection(string $connection = self::DEFAULT_CONNECTION): string
    {
        return 'connections.' . $connection;
    }

    /**
     * Pinker dot-path prefix for the primary mysql connection.
     * @deprecated Use {@see pinkerPathForConnection()}
     */
    public static function pinkerPathPrefix(): string
    {
        return self::pinkerPathForConnection(self::DEFAULT_CONNECTION);
    }

    /**
     * @param array<string, mixed> $config Connection driver config
     * @return array<string, scalar|null>
     */
    public static function toEnvVariables(array $config, ?string $appEnv = null): array
    {
        $appEnv = RuntimeMode::normalize($appEnv ?? (string) (SystemConfig::env('APP_ENV') ?: RuntimeMode::DEFAULT));

        return [
            'APP_ENV' => $appEnv,
            'DB_CONNECTION' => self::connectionNameFromEnvOrDefault($appEnv),
            'DB_DRIVER' => (string) ($config['driver'] ?? 'mysql'),
            'DB_HOST' => (string) ($config['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($config['port'] ?? '3306'),
            'DB_DATABASE' => (string) ($config['database'] ?? ''),
            'DB_USERNAME' => (string) ($config['username'] ?? 'root'),
            'DB_PASSWORD' => $config['password'] ?? '',
            'DB_CHARSET' => (string) ($config['charset'] ?? 'utf8mb4'),
            'DB_COLLATION' => (string) ($config['collation'] ?? 'utf8mb4_bin'),
            'DB_PREFIX' => (string) ($config['prefix'] ?? DatabaseManager::DEFAULT_CORE_TABLE_PREFIX),
            'DB_STRICT' => $config['strict'] ?? true,
            'DB_ENGINE' => $config['engine'] ?? null,
            'DB_TIMEZONE' => (string) ($config['timezone'] ?? '+03:30'),
        ];
    }

    private static function connectionNameFromEnvOrDefault(string $appEnv): string
    {
        return match (RuntimeMode::normalize($appEnv)) {
            RuntimeMode::TEST, RuntimeMode::DEVELOPMENT => self::DEVDB_CONNECTION,
            default => self::DEFAULT_CONNECTION,
        };
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private static function fromLegacyProfiles(array $root): array
    {
        $connections = [];

        foreach (['production', 'staging', 'development'] as $profile) {
            if (isset($root[$profile]) && is_array($root[$profile])) {
                $connections[self::DEFAULT_CONNECTION] = $root[$profile];
                break;
            }
        }

        if (isset($root['test']) && is_array($root['test'])) {
            $connections[self::TEST_CONNECTION] = $root['test'];
        }

        if ($connections === []) {
            $connections[self::DEFAULT_CONNECTION] = [
                'driver' => 'mysql',
            ];
        }

        if (!isset($connections[self::TEST_CONNECTION])) {
            $connections[self::TEST_CONNECTION] = [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        if (!isset($connections[self::DEVDB_CONNECTION])) {
            $connections[self::DEVDB_CONNECTION] = [
                'driver' => self::DEVDB_CONNECTION,
                'database' => 'devdb',
                'path' => SystemConfig::resolvePath('~/storage/devdb'),
                'prefix' => DatabaseManager::DEFAULT_CORE_TABLE_PREFIX,
            ];
        }

        $legacyDefault = (string) ($root['default'] ?? self::DEFAULT_CONNECTION);

        return [
            'default' => self::normalizeDefaultKey($legacyDefault),
            'connections' => $connections,
        ];
    }

    private static function normalizeDefaultKey(string $default): string
    {
        return match (RuntimeMode::normalize($default)) {
            RuntimeMode::TEST => self::TEST_CONNECTION,
            RuntimeMode::PRODUCTION, RuntimeMode::STAGING, RuntimeMode::DEVELOPMENT => self::DEFAULT_CONNECTION,
            default => $default !== '' ? $default : self::DEFAULT_CONNECTION,
        };
    }

    /**
     * Pinker overrides from before the connections.* layout may sit as top-level production.* keys.
     *
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private static function mergeLegacyProfileKeys(array $root): array
    {
        $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

        if (isset($root['test']) && is_array($root['test'])) {
            $connections[self::TEST_CONNECTION] = array_replace(
                $connections[self::TEST_CONNECTION] ?? [],
                $root['test'],
            );
            unset($root['test']);
        }

        foreach (['production', 'staging', 'development'] as $profile) {
            if (!isset($root[$profile]) || !is_array($root[$profile])) {
                continue;
            }

            $connections[self::DEFAULT_CONNECTION] = array_replace(
                $connections[self::DEFAULT_CONNECTION] ?? [],
                $root[$profile],
            );
            unset($root[$profile]);
        }

        $root['connections'] = $connections;

        return $root;
    }

    /**
     * @param array<string, mixed> $root Normalized config root
     */
    private static function resolveConnectionName(array $root, string $requested, bool $forConnection = true): string
    {
        if ($requested === self::DEVDB_CONNECTION) {
            if (!$forConnection || self::isLocalRuntime()) {
                return self::DEVDB_CONNECTION;
            }

            throw new \RuntimeException('Pinoox DevDB can only be used when APP_ENV=local or APP_ENV=development.');
        }

        if ($requested !== self::AUTO_CONNECTION) {
            return $requested;
        }

        if (self::realDatabaseAvailable($root, self::DEFAULT_CONNECTION)) {
            return self::DEFAULT_CONNECTION;
        }

        if (self::sqliteAvailable($root)) {
            return self::TEST_CONNECTION;
        }

        if (self::isLocalRuntime()) {
            return self::DEVDB_CONNECTION;
        }

        throw new \RuntimeException(
            'Database is not available and Pinoox DevDB is disabled outside local development. '
            . 'Configure MySQL/PostgreSQL/SQLite or set APP_ENV=local for DevDB.',
        );
    }

    /**
     * @param array<string, mixed> $root
     */
    private static function realDatabaseAvailable(array $root, string $connection): bool
    {
        $config = $root['connections'][$connection] ?? null;
        if (!is_array($config)) {
            return false;
        }

        $driver = (string) ($config['driver'] ?? '');
        if (in_array($driver, ['sqlite', self::DEVDB_CONNECTION], true)) {
            return false;
        }

        $extension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlsrv' => 'pdo_sqlsrv',
            default => null,
        };

        if ($extension === null || !extension_loaded('pdo') || !extension_loaded($extension)) {
            return false;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            return false;
        }

        $dsn = match ($driver) {
            'mysql', 'mariadb' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $config['charset'] ?? 'utf8mb4'),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            'sqlsrv' => sprintf('sqlsrv:Server=%s,%d;Database=%s', $host, $port, $database),
            default => null,
        };

        if ($dsn === null) {
            return false;
        }

        try {
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $root
     */
    private static function sqliteAvailable(array $root): bool
    {
        $config = $root['connections'][self::TEST_CONNECTION] ?? null;
        if (!is_array($config) || !extension_loaded('pdo_sqlite')) {
            return false;
        }

        $database = (string) ($config['database'] ?? '');

        return $database === ':memory:' || ($database !== '' && is_file($database));
    }

    private static function effectiveConnectionName(string $name): string
    {
        if ($name === self::DEVDB_CONNECTION && !self::isLocalRuntime()) {
            return self::DEFAULT_CONNECTION;
        }

        return $name;
    }

    private static function isLocalRuntime(): bool
    {
        $appEnv = SystemConfig::env('APP_ENV');
        $testing = filter_var(SystemConfig::env('PINOOX_TESTING', false), FILTER_VALIDATE_BOOL);

        if (is_string($appEnv) && RuntimeMode::normalize($appEnv) === RuntimeMode::PRODUCTION) {
            return false;
        }

        $runtime = RuntimeMode::fromEnv();

        return $testing || in_array($runtime, [RuntimeMode::DEVELOPMENT, RuntimeMode::TEST], true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function devDbPath(array $config): string
    {
        $path = (string) ($config['path'] ?? SystemConfig::env('DEVDB_PATH', ''));

        if ($path === '') {
            $path = SystemConfig::resolvePath('~/storage/devdb');
        }

        $path = SystemConfig::resolvePath($path);
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
