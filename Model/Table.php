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

namespace Pinoox\Model;

use Pinoox\Portal\Database\DB;

class Table
{
    const USER = 'user';
    const FILE = 'file';
    const TOKEN = 'token';
    const HISTORY = 'history';
    const MIGRATION = 'history';
    const ROLE = 'role';
    const PERMISSION = 'permission';
    const ROLE_PERMISSION = 'role_permission';
    const USER_ROLE = 'user_role';

    public static function sqlAlias(string $alias, ?string $package = 'platform'): string
    {
        return DB::sqlAlias($alias, $package);
    }

    public static function sqlCol(string $alias, string $column, ?string $package = 'platform'): string
    {
        return DB::sqlCol($alias, $column, $package);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        $alias = $arguments[0] ?? $name;
        $name = strtoupper($name);
        $table = DB::tableName(constant(static::class . '::' . $name), 'platform');
        if ($alias) {
            $table .= ' AS ' . $alias;
        }

        return $table;
    }
}
