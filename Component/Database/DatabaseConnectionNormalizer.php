<?php

namespace Pinoox\Component\Database;

use Pinoox\Portal\Database\DB;

/**
 * Normalize CLI / installer database credentials into Illuminate connection config.
 */
final class DatabaseConnectionNormalizer
{
    /** @return list<string> */
    public const INSTALLABLE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlsrv', 'sqlite'];

    /** @var array<string, string> */
    public const DRIVER_LABELS = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
    ];

    /**
     * @param array<string, mixed> $input
     */
    public static function driverName(array $input, string $fallback = DatabaseConfig::DEFAULT_CONNECTION): string
    {
        $name = strtolower(trim((string) ($input['driver'] ?? $input['connection'] ?? $fallback)));

        return in_array($name, self::INSTALLABLE_DRIVERS, true) ? $name : $fallback;
    }

    public static function defaultPort(string $driver): string
    {
        return match ($driver) {
            'pgsql' => '5432',
            'sqlsrv' => '1433',
            default => '3306',
        };
    }

    /**
     * @return array{available: bool, extension: ?string}
     */
    public static function extensionStatus(string $driver): array
    {
        return match ($driver) {
            'pgsql' => [
                'available' => extension_loaded('pdo_pgsql'),
                'extension' => extension_loaded('pdo_pgsql') ? 'PDO PostgreSQL' : null,
            ],
            'sqlsrv' => [
                'available' => extension_loaded('pdo_sqlsrv'),
                'extension' => extension_loaded('pdo_sqlsrv') ? 'PDO SQL Server' : null,
            ],
            'mysql', 'mariadb' => [
                'available' => extension_loaded('pdo_mysql') || extension_loaded('mysqli'),
                'extension' => extension_loaded('pdo_mysql')
                    ? 'PDO MySQL'
                    : (extension_loaded('mysqli') ? 'MySQLi' : null),
            ],
            'sqlite' => [
                'available' => extension_loaded('pdo_sqlite'),
                'extension' => extension_loaded('pdo_sqlite') ? 'PDO SQLite' : null,
            ],
            default => ['available' => false, 'extension' => null],
        };
    }

    /** @return list<string> */
    public static function availableDrivers(): array
    {
        $available = [];

        foreach (self::INSTALLABLE_DRIVERS as $driver) {
            if (self::extensionStatus($driver)['available']) {
                $available[] = $driver;
            }
        }

        return $available;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, ?string $driver = null): array
    {
        $driver = $driver ?? self::driverName($input);
        $port = (string) ($input['port'] ?? '');

        if ($port === '') {
            $port = self::defaultPort($driver);
        }

        $shared = [
            'host' => (string) ($input['host'] ?? '127.0.0.1'),
            'database' => $input['database'] ?? null,
            'username' => (string) ($input['username'] ?? 'root'),
            'password' => $input['password'] ?? '',
            'prefix' => (string) ($input['prefix'] ?? DatabaseManager::DEFAULT_CORE_TABLE_PREFIX),
            'port' => $port,
        ];

        $config = match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $input['database'] ?? ':memory:',
                'prefix' => (string) ($input['prefix'] ?? ''),
                'foreign_key_constraints' => $input['foreign_key_constraints'] ?? true,
            ],
            'pgsql' => array_merge($shared, [
                'driver' => 'pgsql',
                'charset' => 'utf8',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]),
            'sqlsrv' => array_merge($shared, [
                'driver' => 'sqlsrv',
                'charset' => 'utf8',
                'prefix_indexes' => true,
            ]),
            'mariadb' => array_merge($shared, [
                'driver' => 'mariadb',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'engine' => 'InnoDB',
                'timezone' => (string) ($input['timezone'] ?? '+03:30'),
            ]),
            default => array_merge($shared, [
                'driver' => 'mysql',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_bin',
                'strict' => true,
                'engine' => 'InnoDB',
                'timezone' => (string) ($input['timezone'] ?? '+03:30'),
            ]),
        };

        return DatabaseConfig::normalizeConnectionDriver($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function test(array $config): bool
    {
        DB::ensureRegistered();

        if (empty($config['database'])) {
            return false;
        }

        $driver = strtolower(trim((string) ($config['driver'] ?? '')));

        if ($driver === 'sqlite') {
            return self::testSqlite($config);
        }

        $driverName = self::driverName($config, $driver !== '' ? $driver : DatabaseConfig::DEFAULT_CONNECTION);

        if (!self::extensionStatus($driverName)['available']) {
            return false;
        }

        if (self::testMatchingRegisteredConnection($config)) {
            return true;
        }

        return self::testProbeConnection($config, $driverName);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function testSqlite(array $config): bool
    {
        if (!self::extensionStatus('sqlite')['available']) {
            return false;
        }

        $database = (string) ($config['database'] ?? '');

        if ($database !== ':memory:' && $database !== '' && !is_file($database)) {
            return false;
        }

        return self::testProbeConnection($config, 'sqlite');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function testMatchingRegisteredConnection(array $config): bool
    {
        foreach (['platform', 'default'] as $name) {
            try {
                $connection = DB::___()->getConnection($name);
                $connection->getPdo();

                if (self::connectionEndpointsMatch($config, $connection->getConfig())) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function connectionEndpointsMatch(array $expected, array $actual): bool
    {
        $actual = DatabaseConfig::normalizeConnectionDriver($actual);
        $expectedDriver = DatabaseConfig::normalizeConnectionDriver([
            'driver' => (string) ($expected['driver'] ?? 'mysql'),
        ])['driver'];

        foreach (['host', 'database', 'username', 'port'] as $key) {
            if ((string) ($expected[$key] ?? '') !== (string) ($actual[$key] ?? '')) {
                return false;
            }
        }

        return (string) ($actual['driver'] ?? '') === (string) $expectedDriver;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function testProbeConnection(array $config, string $driver): bool
    {
        $normalized = DatabaseConfig::normalizeConnectionDriver(array_replace(
            self::normalize($config, $driver),
            array_intersect_key($config, array_flip([
                'unix_socket',
                'options',
                'url',
                'charset',
                'collation',
                'strict',
                'engine',
                'timezone',
                'prefix_indexes',
                'search_path',
                'sslmode',
            ])),
        ));

        $probeName = '__pinoox_db_probe_' . bin2hex(random_bytes(4));
        $capsule = DB::___();

        try {
            $capsule->addConnection($normalized, $probeName);
            $capsule->getConnection($probeName)->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $capsule->getDatabaseManager()->purge($probeName);
            } catch (\Throwable) {
            }
        }
    }
}
