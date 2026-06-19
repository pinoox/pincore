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

namespace Pinoox\Component\Database\Connections\Concerns;

use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;

/**
 * Illuminate <=11 stores the table prefix on the grammar instance; Illuminate 12 reads it from the connection.
 */
trait CreatesQueryGrammar
{
    protected function getDefaultQueryGrammar()
    {
        return $this->createQueryGrammar();
    }

    protected function createQueryGrammar(): QueryGrammar
    {
        $grammarClass = $this->queryGrammarClass();
        $constructor = (new \ReflectionClass(Grammar::class))->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return new $grammarClass($this);
        }

        /** @var QueryGrammar $grammar */
        $grammar = new $grammarClass();
        $grammar->setConnection($this);

        return method_exists($this, 'withTablePrefix')
            ? $this->withTablePrefix($grammar)
            : $grammar;
    }

    /** @return class-string<QueryGrammar> */
    abstract protected function queryGrammarClass(): string;
}
