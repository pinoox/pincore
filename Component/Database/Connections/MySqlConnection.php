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

use Illuminate\Database\MySqlConnection as BaseConnection;
use Pinoox\Component\Database\Query\Builder;
use Pinoox\Component\Database\Query\Grammars\MySqlGrammar;

class MySqlConnection extends BaseConnection
{
    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    protected function getDefaultQueryGrammar()
    {
        ($grammar = new MySqlGrammar())->setConnection($this);

        return $this->withTablePrefix($grammar);
    }
}
