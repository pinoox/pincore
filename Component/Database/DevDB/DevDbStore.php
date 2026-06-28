<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Support\SystemConfig;

final class DevDbStore
{
    public const SCHEMA_VERSION = 1;

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?? SystemConfig::resolvePath('~/storage/devdb')), '/');
        $this->ensureDirectories();
    }

    public function root(): string
    {
        return $this->root;
    }

    public function hasTable(string $table): bool
    {
        $schema = $this->schema();

        return isset($schema['tables'][$table]);
    }

    public function schema(): array
    {
        return $this->readJson($this->root . '/schema.json', $this->emptySchema());
    }

    public function saveSchema(array $schema): void
    {
        $schema['version'] ??= self::SCHEMA_VERSION;
        $schema['tables'] ??= [];
        $this->writeJson($this->root . '/schema.json', $schema);
        $this->writeJson($this->root . '/meta/indexes.json', $this->buildIndexMetadata($schema));
    }

    public function createTable(string $table, array $columns, array $indexes = []): void
    {
        $schema = $this->schema();
        $schema['tables'][$table] = [
            'columns' => $columns,
            'indexes' => $indexes,
            'primary_key' => $this->primaryKeyFromColumns($columns),
            'updated_at' => date(DATE_ATOM),
        ];
        $this->saveSchema($schema);
        $this->writeJson($this->dataPath($table), $this->readTable($table));

        $sequences = $this->sequences();
        $sequences[$table] ??= 0;
        $this->saveSequences($sequences);
    }

    public function alterTable(string $table, array $columns, array $indexes = []): void
    {
        $schema = $this->schema();
        $current = $schema['tables'][$table] ?? ['columns' => [], 'indexes' => []];
        $current['columns'] = array_replace($current['columns'] ?? [], $columns);
        $current['indexes'] = $this->uniqueIndexes(array_merge($current['indexes'] ?? [], $indexes));
        $current['primary_key'] = $this->primaryKeyFromColumns($current['columns']);
        $current['updated_at'] = date(DATE_ATOM);
        $schema['tables'][$table] = $current;
        $this->saveSchema($schema);
    }

    public function dropTable(string $table): void
    {
        $schema = $this->schema();
        unset($schema['tables'][$table]);
        $this->saveSchema($schema);

        $path = $this->dataPath($table);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function readTable(string $table): array
    {
        return $this->readJson($this->dataPath($table), []);
    }

    public function replaceTable(string $table, array $rows): void
    {
        $this->writeJson($this->dataPath($table), array_values($rows));
    }

    public function pathForTable(string $table): string
    {
        return $this->dataPath($table);
    }

    public function clear(): void
    {
        $this->removeDirectory($this->root);
        $this->ensureDirectories();
        $this->saveSchema($this->emptySchema());
        $this->saveSequences([]);
        $this->writeJson($this->root . '/meta/migrations.json', []);
    }

    public function status(): array
    {
        $schema = $this->schema();
        $tables = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $tables[] = [
                'table' => (string) $table,
                'columns' => count($meta['columns'] ?? []),
                'rows' => count($this->readTable((string) $table)),
                'primary_key' => $meta['primary_key'] ?? null,
            ];
        }

        return [
            'path' => $this->root,
            'schema_version' => $schema['version'] ?? self::SCHEMA_VERSION,
            'table_count' => count($tables),
            'tables' => $tables,
            'migration_count' => count($this->migrations()),
        ];
    }

    public function inspectTable(string $table, int $limit = 10): array
    {
        $schema = $this->schema();
        $meta = $schema['tables'][$table] ?? null;

        if (!is_array($meta)) {
            throw new DevDbException('DevDB table "' . $table . '" does not exist.');
        }

        return [
            'table' => $table,
            'columns' => $meta['columns'] ?? [],
            'indexes' => $meta['indexes'] ?? [],
            'primary_key' => $meta['primary_key'] ?? null,
            'rows' => array_slice($this->readTable($table), 0, max(0, $limit)),
            'row_count' => count($this->readTable($table)),
        ];
    }

    public function export(): array
    {
        $schema = $this->schema();
        $data = [];

        foreach (array_keys($schema['tables'] ?? []) as $table) {
            $data[$table] = $this->readTable((string) $table);
        }

        return [
            'schema' => $schema,
            'data' => $data,
            'meta' => [
                'migrations' => $this->migrations(),
                'sequences' => $this->sequences(),
                'indexes' => $this->readJson($this->root . '/meta/indexes.json', []),
            ],
        ];
    }

    public function nextId(string $table): int
    {
        $sequences = $this->sequences();
        $sequences[$table] = (int) ($sequences[$table] ?? 0) + 1;
        $this->saveSequences($sequences);

        return $sequences[$table];
    }

    public function sequences(): array
    {
        return $this->readJson($this->root . '/meta/sequences.json', []);
    }

    public function saveSequences(array $sequences): void
    {
        $this->writeJson($this->root . '/meta/sequences.json', $sequences);
    }

    public function migrations(): array
    {
        return $this->readJson($this->root . '/meta/migrations.json', []);
    }

    public function recordMigration(string $package, string $migration, int $batch): void
    {
        $records = $this->migrations();
        foreach ($records as $record) {
            if (($record['package'] ?? null) === $package && ($record['migration'] ?? null) === $migration) {
                return;
            }
        }

        $records[] = [
            'package' => $package,
            'migration' => $migration,
            'batch' => $batch,
            'created_at' => date(DATE_ATOM),
        ];
        $this->writeJson($this->root . '/meta/migrations.json', $records);
    }

    private function dataPath(string $table): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $table);

        return $this->root . '/data/' . $safe . '.json';
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->root, $this->root . '/data', $this->root . '/meta'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    private function emptySchema(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'generated_by' => 'pinoox-devdb',
            'tables' => [],
        ];
    }

    private function primaryKeyFromColumns(array $columns): ?string
    {
        foreach ($columns as $name => $column) {
            if (!empty($column['primary']) || !empty($column['auto_increment'])) {
                return (string) $name;
            }
        }

        return null;
    }

    private function buildIndexMetadata(array $schema): array
    {
        $indexes = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $tableIndexes = [];
            if (!empty($meta['primary_key'])) {
                $tableIndexes['primary'] = [
                    'type' => 'primary',
                    'columns' => [(string) $meta['primary_key']],
                ];
            }

            foreach (($meta['indexes'] ?? []) as $index) {
                $columns = $index['columns'] ?? $index['column'] ?? [];
                $columns = is_array($columns) ? array_values($columns) : [$columns];
                $name = (string) ($index['index'] ?? $index['name'] ?? implode('_', $columns));

                if ($name !== '') {
                    $tableIndexes[$name] = [
                        'type' => (string) ($index['name'] ?? 'index'),
                        'columns' => $columns,
                    ];
                }
            }

            $indexes[(string) $table] = $tableIndexes;
        }

        return $indexes;
    }

    private function uniqueIndexes(array $indexes): array
    {
        $seen = [];
        $unique = [];

        foreach ($indexes as $index) {
            $key = json_encode($index);
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $index;
        }

        return $unique;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : $default;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new DevDbException('Unable to write DevDB file: ' . $path);
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
