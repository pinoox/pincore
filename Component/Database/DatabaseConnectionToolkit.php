<?php

namespace Pinoox\Component\Database;

use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\Platform;

/**
 * Inspect and persist platform / app database connections.
 */
final class DatabaseConnectionToolkit
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listPlatformConnections(bool $test = false): array
    {
        $root = PlatformDatabaseStore::platformRoot();
        $default = (string) ($root['default'] ?? DatabaseConfig::DEFAULT_CONNECTION);
        $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];
        $rows = [];

        foreach ($connections as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                continue;
            }

            $rows[] = self::platformRow($name, $config, $default, $test);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describePlatformConnection(string $name, bool $test = true): array
    {
        $root = PlatformDatabaseStore::platformRoot();
        $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

        if (!isset($connections[$name]) || !is_array($connections[$name])) {
            throw new \InvalidArgumentException('Platform connection not found: ' . $name);
        }

        $default = (string) ($root['default'] ?? DatabaseConfig::DEFAULT_CONNECTION);

        return self::platformRow($name, $connections[$name], $default, $test, detailed: true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeApp(string $package, bool $test = true): array
    {
        if (!AppEngine::exists($package)) {
            throw new \InvalidArgumentException('Package not found: ' . $package);
        }

        $config = AppEngine::config($package);
        $database = $config->get('database');
        $table = $config->get('table');
        $databaseBlock = is_array($database) ? $database : null;
        $tableBlock = is_array($table) ? $table : null;

        $resolved = AppDatabaseResolver::resolve($databaseBlock, $tableBlock);
        $mode = self::describeAppMode($databaseBlock, $tableBlock);
        $prefix = AppDatabaseResolver::explicitPrefix($databaseBlock, $tableBlock)
            ?? DB::tablePrefixForPackage($package);

        $defaultConnection = 'default';
        $runtimeConfig = $resolved['default'] ?? null;

        if ($runtimeConfig === null) {
            $runtimeConfig = self::platformRuntimeConfig();
            $defaultConnection = 'platform default';
        }

        $row = [
            'package' => $package,
            'mode' => $mode,
            'prefix' => $prefix !== '' ? $prefix : '—',
            'logical_prefix' => AppDatabaseResolver::explicitPrefix($databaseBlock, $tableBlock) ?? '—',
            'connection' => $defaultConnection,
            'driver' => (string) ($runtimeConfig['driver'] ?? '—'),
            'host' => (string) ($runtimeConfig['host'] ?? '—'),
            'database' => (string) ($runtimeConfig['database'] ?? '—'),
            'username' => (string) ($runtimeConfig['username'] ?? '—'),
            'password' => self::maskSecret($runtimeConfig['password'] ?? ''),
            'port' => (string) ($runtimeConfig['port'] ?? '—'),
            'status' => $test ? self::statusLabel($runtimeConfig) : '—',
            'raw' => $databaseBlock,
        ];

        if (isset($resolved['default']) && is_array($resolved['default'])) {
            $row['runtime_prefix'] = (string) ($resolved['default']['prefix'] ?? '');
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listApps(bool $test = false): array
    {
        $rows = [];

        foreach (AppEngine::all() as $package => $manager) {
            if ($package === Platform::PACKAGE) {
                continue;
            }

            try {
                $rows[] = self::describeApp($package, $test);
            } catch (\Throwable) {
                $rows[] = [
                    'package' => $package,
                    'mode' => 'error',
                    'prefix' => '—',
                    'connection' => '—',
                    'driver' => '—',
                    'host' => '—',
                    'database' => '—',
                    'status' => 'error',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $databaseBlock
     */
    public static function saveAppDatabase(string $package, ?array $databaseBlock): bool
    {
        if (!AppEngine::exists($package)) {
            throw new \InvalidArgumentException('Package not found: ' . $package);
        }

        $config = AppEngine::config($package);

        if ($databaseBlock === null || $databaseBlock === []) {
            $config->remove('database');
        } else {
            $config->set('database', $databaseBlock);
        }

        $config->save();

        return true;
    }

    public static function setAppPrefix(string $package, string $prefix, ?string $use = null): bool
    {
        $prefix = trim($prefix);

        if ($prefix === '') {
            throw new \InvalidArgumentException('Prefix cannot be empty.');
        }

        if (!AppEngine::exists($package)) {
            throw new \InvalidArgumentException('Package not found: ' . $package);
        }

        $current = AppEngine::config($package)->get('database');
        $database = is_array($current) ? $current : [];

        unset($database['table_prefix']);
        $database['prefix'] = $prefix;

        if ($use !== null && $use !== '') {
            $database['use'] = $use;
        } elseif (!isset($database['use']) && !isset($database['connection']) && !isset($database['driver'])) {
            $database['use'] = 'platform';
        }

        return self::saveAppDatabase($package, $database);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function buildAppDatabaseBlock(array $input, ?array $current = null): array
    {
        $current = is_array($current) ? $current : [];
        $database = $current;

        if (($input['reset'] ?? false) === true) {
            return [];
        }

        if (array_key_exists('use', $input) && $input['use'] !== null && $input['use'] !== '') {
            $database['use'] = (string) $input['use'];
            unset($database['connection'], $database['driver']);
        }

        if (array_key_exists('prefix', $input) && $input['prefix'] !== null && $input['prefix'] !== '') {
            $database['prefix'] = (string) $input['prefix'];
        }

        foreach (['host', 'database', 'username', 'password', 'port', 'charset', 'collation', 'timezone'] as $key) {
            if (!array_key_exists($key, $input) || $input[$key] === null) {
                continue;
            }

            $database[$key] = $input[$key];
        }

        if (array_key_exists('driver', $input) && $input['driver'] !== null && $input['driver'] !== '') {
            $database['driver'] = (string) $input['driver'];
            unset($database['use'], $database['connection']);
        }

        return self::cleanupAppDatabaseBlock($database);
    }

    /**
     * @param array<string, mixed> $database
     * @return array<string, mixed>
     */
    public static function cleanupAppDatabaseBlock(array $database): array
    {
        if ($database === []) {
            return [];
        }

        $use = strtolower(trim((string) ($database['use'] ?? $database['connection'] ?? '')));
        $hasDriver = !empty($database['driver']);
        $prefix = AppDatabaseResolver::connectionPrefix($database) ?? AppDatabaseResolver::tablePrefix($database);

        if (!$hasDriver && in_array($use, ['platform', 'default', 'core', ''], true)) {
            if ($prefix !== null) {
                return ['use' => 'platform', 'prefix' => $prefix];
            }

            return ['use' => 'platform'];
        }

        if (!$hasDriver && $use !== '' && $prefix !== null) {
            return ['use' => $use, 'prefix' => $prefix];
        }

        if (!$hasDriver && $prefix !== null && count(array_filter($database, static fn ($v) => $v !== null && $v !== '')) === 1) {
            return ['prefix' => $prefix];
        }

        return $database;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function testConfig(array $config): bool
    {
        DB::ensureRegistered();

        return DatabaseConnectionNormalizer::test($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function platformRow(
        string $name,
        array $config,
        string $default,
        bool $test,
        bool $detailed = false,
    ): array {
        $runtime = DatabaseConfig::normalizeConnectionDriver($config, false);
        $row = [
            'name' => $name,
            'default' => $name === $default ? 'yes' : 'no',
            'driver' => (string) ($runtime['driver'] ?? '—'),
            'host' => (string) ($runtime['host'] ?? '—'),
            'database' => (string) ($runtime['database'] ?? '—'),
            'prefix' => (string) ($runtime['prefix'] ?? '—'),
            'status' => $test ? self::statusLabel($runtime) : '—',
        ];

        if ($detailed) {
            $row['username'] = (string) ($runtime['username'] ?? '—');
            $row['password'] = self::maskSecret($runtime['password'] ?? '');
            $row['port'] = (string) ($runtime['port'] ?? '—');
            $row['charset'] = (string) ($runtime['charset'] ?? '—');
            $row['collation'] = (string) ($runtime['collation'] ?? '—');
            $row['timezone'] = (string) ($runtime['timezone'] ?? '—');
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $databaseBlock
     * @param array<string, mixed>|null $tableBlock
     */
    private static function describeAppMode(?array $databaseBlock, ?array $tableBlock): string
    {
        if ($databaseBlock === null || $databaseBlock === []) {
            return 'platform default';
        }

        if (isset($databaseBlock['connections']) && is_array($databaseBlock['connections'])) {
            return 'multi-connection';
        }

        if (!empty($databaseBlock['driver'])) {
            return 'dedicated';
        }

        $use = strtolower(trim((string) ($databaseBlock['use'] ?? $databaseBlock['connection'] ?? '')));

        if (in_array($use, ['platform', 'default', 'core'], true)) {
            $prefix = AppDatabaseResolver::explicitPrefix($databaseBlock, $tableBlock);

            return $prefix !== null ? 'platform + prefix' : 'platform default';
        }

        if ($use !== '') {
            $prefix = AppDatabaseResolver::explicitPrefix($databaseBlock, $tableBlock);

            return $prefix !== null ? 'platform connection + prefix' : 'platform connection';
        }

        if (AppDatabaseResolver::explicitPrefix($databaseBlock, $tableBlock) !== null) {
            return 'platform + prefix';
        }

        return 'custom';
    }

    /**
     * @return array<string, mixed>
     */
    private static function platformRuntimeConfig(): array
    {
        $root = PlatformDatabaseStore::platformRoot();
        $default = (string) ($root['default'] ?? DatabaseConfig::DEFAULT_CONNECTION);

        return DatabaseConfig::connectionConfig($root, $default, false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function statusLabel(array $config): string
    {
        if (!empty($config['devdb_unavailable'])) {
            return 'local only';
        }

        return self::testConfig($config) ? 'connected' : 'failed';
    }

    private static function maskSecret(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '—';
        }

        if (strlen($value) <= 2) {
            return '**';
        }

        return str_repeat('*', max(4, strlen($value) - 2)) . substr($value, -2);
    }
}
