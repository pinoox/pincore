<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Support\SystemConfig;

final class DevDbStore
{
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
        return $this->readJson($this->root . '/schema.json', ['tables' => []]);
    }

    public function saveSchema(array $schema): void
    {
        $schema['tables'] ??= [];
        $this->writeJson($this->root . '/schema.json', $schema);
    }

    public function createTable(string $table, array $columns, array $indexes = []): void
    {
        $schema = $this->schema();
        $schema['tables'][$table] = [
            'columns' => $columns,
            'indexes' => $indexes,
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
        $current['indexes'] = array_merge($current['indexes'] ?? [], $indexes);
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

