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

namespace Pinoox\Component\Database\Connections;

use Illuminate\Database\SQLiteConnection as BaseConnection;
use Pinoox\Component\Database\Connections\Concerns\CreatesQueryGrammar;
use Pinoox\Component\Database\Query\Builder;
use Pinoox\Component\Database\Query\Grammars\SQLiteGrammar;

class SQLiteConnection extends BaseConnection
{
    use CreatesQueryGrammar;

    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    public function statement($query, $bindings = [])
    {
        $sql = trim((string) $query);

        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*(0|1)/i', $sql, $matches) === 1) {
            return parent::statement('PRAGMA foreign_keys = ' . ((string) $matches[1] === '1' ? 'ON' : 'OFF'));
        }

        return parent::statement($query, $bindings);
    }

    protected function queryGrammarClass(): string
    {
        return SQLiteGrammar::class;
    }
}
