<?php

namespace Pinoox\Component\Database\DevDB;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class DevDbQueryBuilder extends Builder
{
    public function get($columns = ['*'])
    {
        return new Collection($this->projectRows($this->filteredRows(), $columns));
    }

    public function insert(array $values)
    {
        if ($values === []) {
            return true;
        }

        $rows = $this->store()->readTable($this->fromTable());
        $records = array_is_list($values) && isset($values[0]) && is_array($values[0])
            ? $values
            : [$values];

        foreach ($records as $record) {
            $rows[] = $record;
        }

        $this->store()->replaceTable($this->fromTable(), $rows);

        return true;
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $table = $this->fromTable();
        $key = $sequence ?: $this->primaryKey($table);

        if (!isset($values[$key])) {
            $values[$key] = $this->store()->nextId($table);
        }

        $this->insert($values);

        return $values[$key];
    }

    public function update(array $values)
    {
        $table = $this->fromTable();
        $updated = 0;
        $rows = [];

        foreach ($this->store()->readTable($table) as $row) {
            if ($this->matches($row)) {
                $row = array_replace($row, $values);
                $updated++;
            }

            $rows[] = $row;
        }

        $this->store()->replaceTable($table, $rows);

        return $updated;
    }

    public function delete($id = null)
    {
        if ($id !== null) {
            $this->where($this->primaryKey($this->fromTable()), '=', $id);
        }

        $deleted = 0;
        $remaining = [];

        foreach ($this->store()->readTable($this->fromTable()) as $row) {
            if ($this->matches($row)) {
                $deleted++;
                continue;
            }

            $remaining[] = $row;
        }

        $this->store()->replaceTable($this->fromTable(), $remaining);

        return $deleted;
    }

    public function count($columns = '*')
    {
        return count($this->filteredRows(ignoreLimit: true));
    }

    public function exists()
    {
        return $this->count() > 0;
    }

    public function aggregate($function, $columns = ['*'])
    {
        $function = strtolower((string) $function);

        if ($function === 'count') {
            return $this->count($columns);
        }

        if ($function === 'max') {
            $column = is_array($columns) ? (string) ($columns[0] ?? '') : (string) $columns;
            $values = array_map(fn ($row) => $row[$column] ?? null, $this->filteredRows(ignoreLimit: true));
            $values = array_filter($values, fn ($value) => $value !== null);

            return $values === [] ? null : max($values);
        }

        throw DevDbException::unsupported('aggregate "' . $function . '"');
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        throw DevDbException::unsupported('joins');
    }

    private function filteredRows(bool $ignoreLimit = false): array
    {
        $rows = array_values(array_filter(
            $this->store()->readTable($this->fromTable()),
            fn ($row) => $this->matches($row),
        ));

        foreach ($this->orders ?? [] as $order) {
            $column = $this->columnName((string) ($order['column'] ?? ''));
            $direction = strtolower((string) ($order['direction'] ?? 'asc'));
            usort($rows, function ($a, $b) use ($column, $direction) {
                $result = ($a[$column] ?? null) <=> ($b[$column] ?? null);

                return $direction === 'desc' ? -$result : $result;
            });
        }

        if (!$ignoreLimit) {
            $offset = (int) ($this->offset ?? 0);
            $limit = $this->limit;
            if ($offset > 0 || $limit !== null) {
                $rows = array_slice($rows, $offset, $limit);
            }
        }

        return $rows;
    }

    private function matches(array $row): bool
    {
        foreach ($this->wheres ?? [] as $where) {
            $type = $where['type'] ?? 'Basic';
            $boolean = strtolower((string) ($where['boolean'] ?? 'and'));

            if ($boolean !== 'and') {
                throw DevDbException::unsupported('OR where clauses');
            }

            $matched = match ($type) {
                'Basic' => $this->compare($row[$this->columnName($where['column'])] ?? null, $where['operator'], $where['value']),
                'In' => in_array($row[$this->columnName($where['column'])] ?? null, $where['values'] ?? [], true),
                'Null' => ($row[$this->columnName($where['column'])] ?? null) === null,
                default => throw DevDbException::unsupported('where type "' . $type . '"'),
            };

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match (strtolower($operator)) {
            '=', '==' => $actual == $expected,
            '!=', '<>' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'like' => $this->like((string) $actual, (string) $expected),
            default => throw DevDbException::unsupported('operator "' . $operator . '"'),
        };
    }

    private function like(string $actual, string $pattern): bool
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

        return preg_match($regex, $actual) === 1;
    }

    private function projectRows(array $rows, mixed $columns): array
    {
        $columns = $this->normalizeColumns($columns);
        if ($columns === ['*']) {
            return array_map(fn ($row) => (object) $row, $rows);
        }

        return array_map(function ($row) use ($columns) {
            $projected = [];
            foreach ($columns as $column) {
                $name = $this->columnName($column);
                $projected[$name] = $row[$name] ?? null;
            }

            return (object) $projected;
        }, $rows);
    }

    private function normalizeColumns(mixed $columns): array
    {
        if ($columns === null || $columns === ['*'] || $columns === '*') {
            return ['*'];
        }

        return is_array($columns) ? $columns : [$columns];
    }

    private function fromTable(): string
    {
        $table = (string) $this->from;

        if (preg_match('/^(.+?)\s+as\s+.+$/i', $table, $matches)) {
            return trim($matches[1]);
        }

        return trim($table);
    }

    private function columnName(mixed $column): string
    {
        $column = (string) $column;

        if (str_contains($column, '.')) {
            $column = substr($column, strrpos($column, '.') + 1);
        }

        if (stripos($column, ' as ') !== false) {
            $column = trim(substr($column, stripos($column, ' as ') + 4));
        }

        return trim($column, '`" ');
    }

    private function primaryKey(string $table): string
    {
        $schema = $this->store()->schema();
        foreach (($schema['tables'][$table]['columns'] ?? []) as $name => $column) {
            if (!empty($column['auto_increment']) || !empty($column['primary'])) {
                return (string) $name;
            }
        }

        return 'id';
    }

    private function store(): DevDbStore
    {
        return $this->connection->devDbStore();
    }
}

