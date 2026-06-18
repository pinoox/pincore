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

namespace Pinoox\Component\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;

/**
 * Rewrites short table aliases in raw SQL to match Laravel grammar output
 * when the connection applies a table prefix (e.g. p.post_id → paper_p.post_id).
 */
final class SqlAliasRewriter
{
    public static function qualifyRaw(string $sql, Builder $query): string
    {
        $prefix = (string) $query->getConnection()->getTablePrefix();

        if ($prefix === '' || $sql === '') {
            return $sql;
        }

        foreach (self::collectQueryAliases($query) as $alias) {
            if (str_starts_with($alias, $prefix)) {
                continue;
            }

            $qualified = $prefix . $alias;
            $pattern = '/(?<![\w])' . preg_quote($alias, '/') . '\./i';
            $sql = (string) preg_replace($pattern, $qualified . '.', $sql);
        }

        return $sql;
    }

    /**
     * @return list<string> lowercase aliases from FROM / JOIN clauses
     */
    public static function collectQueryAliases(Builder $query): array
    {
        $aliases = [];

        if (is_string($query->from) && ($alias = self::parseTableAlias($query->from))) {
            $aliases[] = $alias;
        }

        foreach ($query->joins ?? [] as $join) {
            $table = $join->table ?? null;

            if ($table instanceof Expression || !is_string($table)) {
                continue;
            }

            if ($alias = self::parseTableAlias($table)) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    public static function parseTableAlias(string $table): ?string
    {
        $table = str_replace([' as ', ' AS '], '|', $table);

        if (!str_contains($table, '|')) {
            return null;
        }

        $parts = explode('|', $table);
        $alias = trim((string) end($parts));
        $alias = str_replace(['"', "'", '`'], '', $alias);
        $alias = strtolower($alias);

        return $alias !== '' ? $alias : null;
    }
}
