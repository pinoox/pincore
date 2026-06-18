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

use Illuminate\Database\Connection;
use Pinoox\Component\Database\Connections\MySqlConnection;
use Pinoox\Component\Database\Connections\PostgresConnection;
use Pinoox\Component\Database\Connections\SQLiteConnection;

final class PinooxConnectionFactory
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        Connection::resolverFor('sqlite', static fn ($pdo, $database, $tablePrefix, $config) => new SQLiteConnection($pdo, $database, $tablePrefix, $config));

        Connection::resolverFor('mysql', static fn ($pdo, $database, $tablePrefix, $config) => new MySqlConnection($pdo, $database, $tablePrefix, $config));

        Connection::resolverFor('mariadb', static fn ($pdo, $database, $tablePrefix, $config) => new MySqlConnection($pdo, $database, $tablePrefix, $config));

        Connection::resolverFor('pgsql', static fn ($pdo, $database, $tablePrefix, $config) => new PostgresConnection($pdo, $database, $tablePrefix, $config));
    }
}
