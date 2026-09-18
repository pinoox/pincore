<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Database\Query\Grammars\Concerns;

use Illuminate\Database\Query\Builder;

/**
 * Prefix table names but keep SQL aliases short (p, t, u) in FROM/JOIN/UPDATE/DELETE.
 *
 * Auto-aliases let Eloquent qualify columns with the logical table name
 * (`packages.status`). MySQL rejects `DELETE FROM tbl AS alias` on many
 * versions, so DELETE is compiled as `DELETE alias FROM tbl AS alias`.
 * INSERT/TRUNCATE still wrap tables without aliases.
 */
trait KeepsShortTableAliases
{
    /**
     * Whether wrapTable() should emit `physical AS logical` aliases.
     */
    protected bool $aliasPrefixedTables = true;

    /**
     * Prefix physical table names while preserving the logical name as a SQL alias.
     * BelongsToMany pivot joins pass the logical table (e.g. user_role); Eloquent
     * qualifies pivot columns with that name, so prefixed joins need "AS user_role".
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $table
     * @param  string|null  $prefix
     * @return string
     */
    public function wrapTable($table, $prefix = null): string
    {
        if ($this->isExpression($table)) {
            return (string) $this->getValue($table);
        }

        if (stripos($table, ' as ') !== false) {
            return $this->wrapAliasedTable($table, $prefix);
        }

        $prefix ??= $this->connection->getTablePrefix();

        if (!$this->aliasPrefixedTables) {
            return (string) parent::wrapTable($table, $prefix);
        }

        if (str_contains($table, '.')) {
            return (string) parent::wrapTable($table, $prefix);
        }

        if ($prefix === '') {
            return (string) parent::wrapTable($table, $prefix);
        }

        if (str_starts_with($table, $prefix)) {
            $logical = substr($table, strlen($prefix));

            if ($logical !== '') {
                return $this->wrapValue($table) . ' as ' . $this->wrapValue($logical);
            }

            return $this->wrapValue($table);
        }

        return $this->wrapValue($prefix . $table) . ' as ' . $this->wrapValue($table);
    }

    /**
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withoutTableAliases(callable $callback): mixed
    {
        $previous = $this->aliasPrefixedTables;
        $this->aliasPrefixedTables = false;

        try {
            return $callback();
        } finally {
            $this->aliasPrefixedTables = $previous;
        }
    }

    /**
     * @param  string  $value
     * @param  string|null  $prefix
     * @return string
     */
    protected function wrapAliasedTable($value, $prefix = null): string
    {
        $segments = preg_split('/\s+as\s+/i', $value);
        $prefix ??= $this->connection->getTablePrefix();
        $table = $segments[0];

        if ($prefix !== '' && !str_contains($table, '.') && !str_starts_with($table, $prefix)) {
            $table = $prefix . $table;
        }

        return $this->wrapValue($table) . ' as ' . $this->wrapValue($segments[1]);
    }

    /**
     * @param  string  $value
     * @param  bool  $prefixAlias
     * @return string
     */
    protected function wrapAliasedValue($value, $prefixAlias = false): string
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
    }

    /**
     * @param  array  $segments
     * @return string
     */
    protected function wrapSegments($segments): string
    {
        if (count($segments) > 1) {
            return collect($segments)->map(fn ($segment) => $this->wrapValue($segment))->implode('.');
        }

        return (string) parent::wrapSegments($segments);
    }

    /**
     * @param  Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values): string
    {
        return (string) $this->withoutTableAliases(fn () => parent::compileInsert($query, $values));
    }

    /**
     * @param  Builder  $query
     * @param  array  $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence): string
    {
        return (string) $this->withoutTableAliases(fn () => parent::compileInsertGetId($query, $values, $sequence));
    }

    /**
     * @param  Builder  $query
     * @param  array  $columns
     * @param  string  $sql
     * @return string
     */
    public function compileInsertUsing(Builder $query, array $columns, string $sql): string
    {
        return (string) $this->withoutTableAliases(fn () => parent::compileInsertUsing($query, $columns, $sql));
    }

    /**
     * @param  Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        return (string) $this->withoutTableAliases(fn () => parent::compileInsertOrIgnore($query, $values));
    }

    /**
     * @param  Builder  $query
     * @param  array  $columns
     * @param  string  $sql
     * @return string
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        return (string) $this->withoutTableAliases(fn () => parent::compileInsertOrIgnoreUsing($query, $columns, $sql));
    }

    /**
     * @param  Builder  $query
     * @param  array  $values
     * @param  array  $uniqueBy
     * @param  array  $update
     * @return string
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        return (string) $this->withoutTableAliases(
            fn () => parent::compileUpsert($query, $values, $uniqueBy, $update)
        );
    }

    /**
     * @param  Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query): array
    {
        return (array) $this->withoutTableAliases(fn () => parent::compileTruncate($query));
    }

    /**
     * MySQL rejects `DELETE FROM tbl AS alias` on many versions, so DELETE is
     * compiled as `DELETE alias FROM tbl AS alias`. SQLite/Postgres reject that
     * MySQL form but accept `DELETE FROM physical AS logical` — keep aliases so
     * Eloquent WHERE clauses that qualify columns with the logical name
     * (`history.type`) still resolve after wrapTable() emits `pinx_history AS history`.
     *
     * @param  Builder  $query
     * @return string
     */
    public function compileDelete(Builder $query): string
    {
        if (!$this->usesMysqlDeleteAliasForm()) {
            // Keep short aliases: DELETE FROM "pinx_history" AS "history" WHERE ...
            return (string) parent::compileDelete($query);
        }

        $table = $this->wrapTable($query->from);
        $where = $this->compileWheres($query);

        if (!isset($query->joins) && preg_match('/\s+as\s+((?:`[^`]+`)|(?:\S+))$/i', $table, $m)) {
            $sql = trim("delete {$m[1]} from {$table} {$where}");

            if (!empty($query->orders)) {
                $sql .= ' ' . $this->compileOrders($query, $query->orders);
            }

            if (isset($query->limit)) {
                $sql .= ' ' . $this->compileLimit($query, $query->limit);
            }

            return $sql;
        }

        return (string) parent::compileDelete($query);
    }

    protected function usesMysqlDeleteAliasForm(): bool
    {
        $driver = $this->connection?->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true);
    }
}
