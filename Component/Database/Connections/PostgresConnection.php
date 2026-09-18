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

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query(): Builder
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\PostgresGrammar
     */
    protected function getDefaultQueryGrammar(): \Illuminate\Database\Query\Grammars\PostgresGrammar
    {
        /** @var \Illuminate\Database\Query\Grammars\PostgresGrammar $grammar */
        $grammar = $this->createQueryGrammar();

        return $grammar;
    }

    protected function queryGrammarClass(): string
    {
        return PostgresGrammar::class;
    }
}
