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
     */
    public function wrapTable($table, $prefix = null)
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        if (stripos($table, ' as ') !== false) {
            return $this->wrapAliasedTable($table, $prefix);
        }

        $prefix ??= $this->connection->getTablePrefix();

        if (!$this->aliasPrefixedTables) {
            return parent::wrapTable($table, $prefix);
        }

        if (str_contains($table, '.')) {
            return parent::wrapTable($table, $prefix);
        }

        if ($prefix === '') {
            return parent::wrapTable($table, $prefix);
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

    protected function wrapAliasedTable($value, $prefix = null)
    {
        $segments = preg_split('/\s+as\s+/i', $value);
        $prefix ??= $this->connection->getTablePrefix();
        $table = $segments[0];

        if ($prefix !== '' && !str_contains($table, '.') && !str_starts_with($table, $prefix)) {
            $table = $prefix . $table;
        }

        return $this->wrapValue($table) . ' as ' . $this->wrapValue($segments[1]);
    }

    protected function wrapAliasedValue($value, $prefixAlias = false)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
    }

    protected function wrapSegments($segments)
    {
        if (count($segments) > 1) {
            return collect($segments)->map(fn ($segment) => $this->wrapValue($segment))->implode('.');
        }

        return parent::wrapSegments($segments);
    }

    public function compileInsert(Builder $query, array $values)
    {
        return $this->withoutTableAliases(fn () => parent::compileInsert($query, $values));
    }

    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->withoutTableAliases(fn () => parent::compileInsertGetId($query, $values, $sequence));
    }

    public function compileInsertUsing(Builder $query, array $columns, string $sql)
    {
        return $this->withoutTableAliases(fn () => parent::compileInsertUsing($query, $columns, $sql));
    }

    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        return $this->withoutTableAliases(fn () => parent::compileInsertOrIgnore($query, $values));
    }

    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        return $this->withoutTableAliases(fn () => parent::compileInsertOrIgnoreUsing($query, $columns, $sql));
    }

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        return $this->withoutTableAliases(
            fn () => parent::compileUpsert($query, $values, $uniqueBy, $update)
        );
    }

    public function compileTruncate(Builder $query)
    {
        return $this->withoutTableAliases(fn () => parent::compileTruncate($query));
    }

    /**
     * Keep short aliases (so Eloquent WHERE qualifiers match) but use the
     * multi-table DELETE form MySQL accepts: `DELETE alias FROM tbl AS alias`.
     */
    public function compileDelete(Builder $query)
    {
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

        return parent::compileDelete($query);
    }
}
