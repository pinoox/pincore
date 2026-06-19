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

use Illuminate\Database\PostgresConnection as BaseConnection;
use Pinoox\Component\Database\Connections\Concerns\CreatesQueryGrammar;
use Pinoox\Component\Database\Query\Builder;
use Pinoox\Component\Database\Query\Grammars\PostgresGrammar;

class PostgresConnection extends BaseConnection
{
    use CreatesQueryGrammar;

    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    protected function queryGrammarClass(): string
    {
        return PostgresGrammar::class;
    }
}
