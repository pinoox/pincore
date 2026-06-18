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

use Pinoox\Portal\Database\DB;
use Pinoox\Portal\Mode;

/**
 * Warn in debug mode when raw SQL uses short table aliases while the
 * connection applies a table prefix to aliases (e.g. p → paper_p).
 */
final class DatabaseRawQueryGuard
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        DB::listen(function ($event): void {
            if (!Mode::debug()) {
                return;
            }

            $prefix = (string) $event->connection->getTablePrefix();
            if ($prefix === '') {
                return;
            }

            self::warnShortAliases($event->sql, $prefix);
        });
    }

    public static function warnShortAliases(string $sql, string $prefix): void
    {
        if (!preg_match_all('/\b([a-z]{1,2})\.([a-z_][a-z0-9_]*)\b/i', $sql, $matches, PREG_SET_ORDER)) {
            return;
        }

        $seen = [];

        foreach ($matches as [, $alias, $column]) {
            $alias = strtolower($alias);

            if (isset($seen[$alias])) {
                continue;
            }

            $qualified = $prefix . $alias;

            if (str_contains($sql, $qualified . '.')) {
                continue;
            }

            $seen[$alias] = true;

            trigger_error(
                sprintf(
                    'SQL uses short table alias "%s.%s" but the connection prefix requires "%s.%s" in raw expressions. Use Table::sqlCol(%s, %s) or DB::sqlCol(...).',
                    $alias,
                    $column,
                    $qualified,
                    $column,
                    var_export($alias, true),
                    var_export($column, true),
                ),
                E_USER_NOTICE,
            );
        }
    }
}
