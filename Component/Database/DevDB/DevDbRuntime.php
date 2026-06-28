<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Support\SystemConfig;

final class DevDbRuntime
{
    public function store(): DevDbStore
    {
        return new DevDbStore($this->path());
    }

    public function engine(): string
    {
        $engine = strtolower(trim((string) SystemConfig::env('DEVDB_ENGINE', 'auto')));

        if ($engine !== 'json' && extension_loaded('pdo_sqlite')) {
            return 'sqlite';
        }

        return 'json';
    }

    public function path(): string
    {
        $path = (string) SystemConfig::env('DEVDB_PATH', '');
        if ($path === '') {
            $path = SystemConfig::resolvePath('~/storage/devdb');
        }

        return SystemConfig::resolvePath($path);
    }

    public function sqliteDatabase(): string
    {
        $database = (string) SystemConfig::env('DEVDB_SQLITE_DATABASE', '');

        return $database !== '' ? SystemConfig::resolvePath($database) : $this->path() . '/devdb.sqlite';
    }

    public function status(): array
    {
        if ($this->engine() === 'sqlite') {
            return $this->sqliteStatus();
        }

        $status = $this->store()->status();
        $status['engine'] = 'json';

        return $status;
    }

    public function inspectTable(string $table, int $limit = 10): array
    {
        if ($this->engine() === 'sqlite') {
            return $this->sqliteInspect($table, $limit);
        }

        return $this->store()->inspectTable($table, $limit);
    }

    public function export(): array
    {
        if ($this->engine() === 'sqlite') {
            return $this->sqliteExport();
        }

        return $this->store()->export();
    }

    public function clear(): void
    {
        if ($this->engine() === 'sqlite') {
            $database = $this->sqliteDatabase();
            if (is_file($database)) {
                @unlink($database);
                if (is_file($database)) {
                    $pdo = $this->pdo();
                    foreach ($this->sqliteTables($pdo) as $table) {
                        $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $table) . '"');
                    }
                }
            }

            return;
        }

        $this->store()->clear();
    }

    private function pdo(): \PDO
    {
        $database = $this->sqliteDatabase();
        $dir = dirname($database);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return new \PDO('sqlite:' . $database, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function sqliteStatus(): array
    {
        $database = $this->sqliteDatabase();
        $tables = [];

        if (is_file($database)) {
            $pdo = $this->pdo();
            foreach ($this->sqliteTables($pdo) as $table) {
                $tables[] = [
                    'table' => $table,
                    'columns' => count($this->sqliteColumns($pdo, $table)),
                    'rows' => (int) $pdo->query('SELECT COUNT(*) AS count FROM "' . str_replace('"', '""', $table) . '"')->fetch()['count'],
                    'primary_key' => $this->sqlitePrimaryKey($pdo, $table),
                ];
            }
        }

        return [
            'engine' => 'sqlite',
            'path' => $this->path(),
            'database' => $database,
            'schema_version' => DevDbStore::SCHEMA_VERSION,
            'table_count' => count($tables),
            'tables' => $tables,
            'migration_count' => 0,
        ];
    }

    private function sqliteInspect(string $table, int $limit): array
    {
        $pdo = $this->pdo();
        $columns = $this->sqliteColumns($pdo, $table);
        if ($columns === []) {
            throw new DevDbException('DevDB table "' . $table . '" does not exist.');
        }

        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $rows = $pdo->query('SELECT * FROM ' . $quoted . ' LIMIT ' . max(0, $limit))->fetchAll();
        $count = (int) $pdo->query('SELECT COUNT(*) AS count FROM ' . $quoted)->fetch()['count'];

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => [],
            'primary_key' => $this->sqlitePrimaryKey($pdo, $table),
            'rows' => $rows,
            'row_count' => $count,
        ];
    }

    private function sqliteExport(): array
    {
        $pdo = $this->pdo();
        $schema = [
            'version' => DevDbStore::SCHEMA_VERSION,
            'generated_by' => 'pinoox-devdb-sqlite',
            'tables' => [],
        ];
        $data = [];

        foreach ($this->sqliteTables($pdo) as $table) {
            $schema['tables'][$table] = [
                'columns' => $this->sqliteColumns($pdo, $table),
                'indexes' => [],
                'primary_key' => $this->sqlitePrimaryKey($pdo, $table),
            ];
            $data[$table] = $pdo->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();
        }

        return [
            'schema' => $schema,
            'data' => $data,
            'meta' => [
                'engine' => 'sqlite',
                'database' => $this->sqliteDatabase(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function sqliteTables(\PDO $pdo): array
    {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();

        return array_map(static fn ($row) => (string) $row['name'], $rows);
    }

    private function sqliteColumns(\PDO $pdo, string $table): array
    {
        $statement = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
        if ($statement === false) {
            return [];
        }

        $columns = [];
        foreach ($statement->fetchAll() as $column) {
            $columns[(string) $column['name']] = [
                'type' => strtolower((string) $column['type']),
                'nullable' => (int) $column['notnull'] === 0,
                'default' => $column['dflt_value'],
                'primary' => (int) $column['pk'] > 0,
            ];
        }

        return $columns;
    }

    private function sqlitePrimaryKey(\PDO $pdo, string $table): ?string
    {
        foreach ($this->sqliteColumns($pdo, $table) as $name => $column) {
            if (!empty($column['primary'])) {
                return (string) $name;
            }
        }

        return null;
    }
}
