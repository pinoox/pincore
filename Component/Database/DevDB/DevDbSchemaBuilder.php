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
        $this->store()->alterTable((string) $table, $this->columns($blueprint), $this->indexes($blueprint));
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
                'nullable' => (bool) ($attributes['nullable'] ?? false),
                'default' => $attributes['default'] ?? null,
                'auto_increment' => (bool) ($attributes['autoIncrement'] ?? false),
                'primary' => (bool) ($attributes['primary'] ?? false) || (bool) ($attributes['autoIncrement'] ?? false),
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

    private function store(): DevDbStore
    {
        return $this->connection->devDbStore();
    }
}

