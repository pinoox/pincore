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
use Pinoox\Component\Database\Query\Builder;
use Pinoox\Component\Database\Query\Grammars\SQLiteGrammar;

class SQLiteConnection extends BaseConnection
{
    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    protected function getDefaultQueryGrammar()
    {
        ($grammar = new SQLiteGrammar())->setConnection($this);

        return $this->withTablePrefix($grammar);
    }
}
