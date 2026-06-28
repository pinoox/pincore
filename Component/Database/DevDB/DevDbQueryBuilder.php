<?php

namespace Pinoox\Component\Database\DevDB;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class DevDbQueryBuilder extends Builder
{
    public function get($columns = ['*'])
    {
        if (!empty($this->aggregate)) {
            $function = strtolower((string) ($this->aggregate['function'] ?? ''));
            if (in_array($function, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                return new Collection([(object) ['aggregate' => $this->aggregate($function, $this->aggregate['columns'] ?? ['*'])]]);
            }
        }

        $this->guardUnsupportedQueryShape();

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
        $this->guardUnsupportedQueryShape(allowAggregate: true);

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

        if (in_array($function, ['sum', 'avg', 'min', 'max'], true)) {
            $column = $this->columnName(is_array($columns) ? (string) ($columns[0] ?? '') : (string) $columns);
            $values = array_map(fn ($row) => $this->valueForColumn($row, $column), $this->filteredRows(ignoreLimit: true));
            $values = array_values(array_filter($values, fn ($value) => $value !== null && $value !== ''));

            if ($function === 'sum') {
                return array_sum(array_map('floatval', $values));
            }

            if ($function === 'avg') {
                return $values === [] ? null : array_sum(array_map('floatval', $values)) / count($values);
            }

            return $values === [] ? null : ($function === 'min' ? min($values) : max($values));
        }

        throw DevDbException::unsupported('aggregate "' . $function . '"', $this->fromTable());
    }

    public function value($column)
    {
        $row = $this->first([$column]);
        $column = $this->columnName($column);

        return $row->{$column} ?? null;
    }

    public function pluck($column, $key = null)
    {
        $columnName = $this->columnName($column);
        $keyName = $key !== null ? $this->columnName($key) : null;
        $values = [];

        foreach ($this->filteredRows() as $row) {
            if ($keyName !== null) {
                $values[$row[$keyName] ?? null] = $row[$columnName] ?? null;
                continue;
            }

            $values[] = $row[$columnName] ?? null;
        }

        return new Collection($values);
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $total = $total ?? $this->count();
        $items = $this->forPage($page, $perPage)->get($columns);

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        return parent::join($table, $first, $operator, $second, $type, $where);
    }

    private function filteredRows(bool $ignoreLimit = false): array
    {
        $rows = $this->applyJoins($this->qualifiedRows($this->fromTable()));
        $rows = array_values(array_filter($rows, fn ($row) => $this->matches($row)));

        if (!empty($this->groups)) {
            $rows = $this->groupRows($rows);
        }

        if (!empty($this->havings)) {
            $rows = array_values(array_filter($rows, fn ($row) => $this->matchesHavings($row)));
        }

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
        return $this->matchesWheres($row, $this->wheres ?? []);
    }

    private function matchesWheres(array $row, array $wheres): bool
    {
        $result = null;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? 'Basic';
            $boolean = strtolower((string) ($where['boolean'] ?? 'and'));

            $matched = match (strtolower((string) $type)) {
                'basic' => $this->compare($this->valueForColumn($row, $where['column']), $where['operator'], $where['value']),
                'column' => $this->compare($this->valueForColumn($row, $where['first']), $where['operator'], $this->valueForColumn($row, $where['second'])),
                'in', 'inraw' => in_array($this->valueForColumn($row, $where['column']), $where['values'] ?? [], false),
                'notin', 'notinraw' => !in_array($this->valueForColumn($row, $where['column']), $where['values'] ?? [], false),
                'null' => ($this->valueForColumn($row, $where['column']) === null) === !($where['not'] ?? false),
                'notnull' => $this->valueForColumn($row, $where['column']) !== null,
                'between' => $this->between($this->valueForColumn($row, $where['column']), $where['values'] ?? [], (bool) ($where['not'] ?? false)),
                'nested' => $this->matchesWheres($row, $where['query']->wheres ?? []),
                default => throw DevDbException::unsupported('where type "' . $type . '"', $this->fromTable()),
            };

            $result = $result === null
                ? $matched
                : ($boolean === 'or' ? ($result || $matched) : ($result && $matched));
        }

        return $result ?? true;
    }

    private function matchesHavings(array $row): bool
    {
        $result = null;

        foreach ($this->havings ?? [] as $having) {
            $type = strtolower((string) ($having['type'] ?? 'Basic'));
            if ($type !== 'basic') {
                throw DevDbException::unsupported('having type "' . $type . '"', $this->fromTable());
            }

            $matched = $this->compare($this->valueForColumn($row, $having['column']), $having['operator'], $having['value']);
            $boolean = strtolower((string) ($having['boolean'] ?? 'and'));
            $result = $result === null
                ? $matched
                : ($boolean === 'or' ? ($result || $matched) : ($result && $matched));
        }

        return $result ?? true;
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
            default => throw DevDbException::unsupported('operator "' . $operator . '"', $this->fromTable()),
        };
    }

    private function between(mixed $actual, iterable $values, bool $not): bool
    {
        $values = is_array($values) ? array_values($values) : iterator_to_array($values);
        $matched = count($values) >= 2 && $actual >= $values[0] && $actual <= $values[1];

        return $not ? !$matched : $matched;
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
                [$name, $alias] = $this->selectNameAndAlias($column);
                $projected[$alias] = $this->valueForColumn($row, $name);
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

    private function tableAlias(string $table): string
    {
        if (preg_match('/^.+?\s+as\s+(.+)$/i', $table, $matches)) {
            return trim($matches[1], '`" ');
        }

        return $this->columnName($table);
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

    private function selectNameAndAlias(mixed $column): array
    {
        $column = (string) $column;
        if (stripos($column, ' as ') !== false) {
            [$name, $alias] = preg_split('/\s+as\s+/i', $column, 2);

            return [$this->columnName($name), $this->columnName($alias)];
        }

        $name = $this->columnName($column);

        return [$name, $name];
    }

    private function valueForColumn(array $row, mixed $column): mixed
    {
        $column = trim((string) $column, '`" ');
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        $name = $this->columnName($column);

        return $row[$name] ?? null;
    }

    private function qualifiedRows(string $table): array
    {
        $alias = $this->tableAlias((string) $this->from);

        return array_map(fn ($row) => $this->qualifyRow((array) $row, $table, $alias), $this->store()->readTable($table));
    }

    private function qualifyRow(array $row, string $table, ?string $alias = null): array
    {
        $qualified = $row;
        $labels = array_values(array_unique(array_filter([$table, $alias])));

        foreach ($row as $column => $value) {
            foreach ($labels as $label) {
                $qualified[$label . '.' . $column] = $value;
            }
        }

        return $qualified;
    }

    private function applyJoins(array $rows): array
    {
        foreach ($this->joins ?? [] as $join) {
            $joinType = strtolower((string) $join->type);
            if (!in_array($joinType, ['inner', 'left'], true)) {
                throw DevDbException::unsupported($joinType . ' joins', $this->fromTable());
            }

            $joinTable = $this->tableNameOnly((string) $join->table);
            $joinAlias = $this->tableAlias((string) $join->table);
            $joinRows = array_map(fn ($row) => $this->qualifyRow((array) $row, $joinTable, $joinAlias), $this->store()->readTable($joinTable));
            $joined = [];

            foreach ($rows as $row) {
                $matchedAny = false;
                foreach ($joinRows as $joinRow) {
                    $combined = array_replace($row, $joinRow);
                    if ($this->matchesWheres($combined, $join->wheres ?? [])) {
                        $joined[] = $combined;
                        $matchedAny = true;
                    }
                }

                if (!$matchedAny && $joinType === 'left') {
                    $joined[] = $row;
                }
            }

            $rows = $joined;
        }

        return $rows;
    }

    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $keyParts = array_map(fn ($column) => $this->valueForColumn($row, $column), (array) $this->groups);
            $key = json_encode($keyParts);
            $groups[$key] ??= ['row' => $row, 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        return array_values(array_map(function ($group) {
            $row = $group['row'];
            $row['aggregate_count'] = count($group['rows']);

            foreach ($row as $column => $value) {
                if (str_contains((string) $column, '.')) {
                    continue;
                }

                $values = array_map(fn ($item) => $this->valueForColumn($item, $column), $group['rows']);
                $values = array_values(array_filter($values, fn ($item) => is_numeric($item)));
                if ($values !== []) {
                    $row['sum_' . $column] = array_sum($values);
                    $row['avg_' . $column] = array_sum($values) / count($values);
                    $row['min_' . $column] = min($values);
                    $row['max_' . $column] = max($values);
                }
            }

            return $row;
        }, $groups));
    }

    private function tableNameOnly(string $table): string
    {
        if (preg_match('/^(.+?)\s+as\s+.+$/i', $table, $matches)) {
            return trim($matches[1], '`" ');
        }

        return trim($table, '`" ');
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

    private function guardUnsupportedQueryShape(bool $allowAggregate = false): void
    {
        if (!empty($this->unions)) {
            throw DevDbException::unsupported('unions', $this->fromTable());
        }

        if (!$allowAggregate && !empty($this->aggregate)) {
            $function = (string) ($this->aggregate['function'] ?? 'aggregate');
            throw DevDbException::unsupported('aggregate "' . $function . '"', $this->fromTable());
        }
    }

    private function store(): DevDbStore
    {
        return $this->connection->devDbStore();
    }
}
