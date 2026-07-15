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

/**
 * Prefix table names but keep SQL aliases short (p, t, u) in FROM/JOIN clauses.
 */
trait KeepsShortTableAliases
{
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

    protected function wrapAliasedTable($value, $prefix = null)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        $prefix ??= $this->connection->getTablePrefix();

        return $this->wrapTable($segments[0], $prefix) . ' as ' . $this->wrapValue($segments[1]);
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
}
