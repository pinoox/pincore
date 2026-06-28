<?php

namespace Pinoox\Component\Database\DevDB;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

class DevDbSchemaBuilder extends Builder
{
    public function hasTable($table)
    {
        return $this->store()->hasTable((string) $table);
    }

    public function create($table, Closure $callback)
    {
        $blueprint = $this->createBlueprint($table);
        $callback($blueprint);
        $this->store()->createTable((string) $table, $this->columns($blueprint), $this->indexes($blueprint));
    }

    public function table($table, Closure $callback)
    {
        $blueprint = $this->createBlueprint($table);
        $callback($blueprint);
        $this->applyCommands((string) $table, $blueprint);
    }

    public function drop($table)
    {
        $this->store()->dropTable((string) $table);
    }

    public function dropIfExists($table)
    {
        if ($this->hasTable($table)) {
            $this->drop($table);
        }
    }

    public function getColumnListing($table)
    {
        $schema = $this->store()->schema();

        return array_keys($schema['tables'][(string) $table]['columns'] ?? []);
    }

    public function preview($table, Closure $callback): array
    {
        $blueprint = $this->createBlueprint($table);
        $callback($blueprint);

        return [
            'table' => (string) $table,
            'columns' => $this->columns($blueprint),
            'indexes' => $this->indexes($blueprint),
            'commands' => array_map(
                static fn ($command) => $command->getAttributes(),
                $blueprint->getCommands(),
            ),
        ];
    }

    private function columns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getColumns() as $column) {
            $attributes = $column->getAttributes();
            $name = (string) ($attributes['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $columns[$name] = [
                'type' => (string) ($attributes['type'] ?? 'string'),
                'length' => $attributes['length'] ?? null,
                'nullable' => (bool) ($attributes['nullable'] ?? false),
                'default' => $attributes['default'] ?? null,
                'auto_increment' => (bool) ($attributes['autoIncrement'] ?? false),
                'primary' => (bool) ($attributes['primary'] ?? false) || (bool) ($attributes['autoIncrement'] ?? false),
                'unsigned' => (bool) ($attributes['unsigned'] ?? false),
                'precision' => $attributes['precision'] ?? null,
                'scale' => $attributes['scale'] ?? null,
                'comment' => $attributes['comment'] ?? null,
            ];
        }

        return $columns;
    }

    private function indexes(Blueprint $blueprint): array
    {
        $indexes = [];

        foreach ($blueprint->getCommands() as $command) {
            $attributes = $command->getAttributes();
            $name = (string) ($attributes['name'] ?? '');
            if (in_array($name, ['primary', 'unique', 'index', 'foreign'], true)) {
                $indexes[] = $attributes;
            }
        }

        return $indexes;
    }

    private function applyCommands(string $table, Blueprint $blueprint): void
    {
        $schema = $this->store()->schema();
        $current = $schema['tables'][$table] ?? ['columns' => [], 'indexes' => []];
        $columns = $current['columns'] ?? [];
        $indexes = $current['indexes'] ?? [];

        foreach ($blueprint->getCommands() as $command) {
            $attributes = $command->getAttributes();
            $name = (string) ($attributes['name'] ?? '');

            if ($name === 'dropColumn') {
                foreach ((array) ($attributes['columns'] ?? []) as $column) {
                    unset($columns[(string) $column]);
                }
                continue;
            }

            if ($name === 'renameColumn') {
                $from = (string) ($attributes['from'] ?? '');
                $to = (string) ($attributes['to'] ?? '');
                if ($from !== '' && $to !== '' && isset($columns[$from])) {
                    $columns[$to] = $columns[$from];
                    unset($columns[$from]);
                }
                continue;
            }

            if (in_array($name, ['primary', 'unique', 'index', 'foreign'], true)) {
                $indexes[] = $attributes;
            }
        }

        $columns = array_replace($columns, $this->columns($blueprint));
        $this->store()->alterTable($table, $columns, $indexes);
    }

    private function store(): DevDbStore
    {
        return $this->connection->devDbStore();
    }
}
