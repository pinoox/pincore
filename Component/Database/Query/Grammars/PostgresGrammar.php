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

namespace Pinoox\Component\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\PostgresGrammar as BaseGrammar;
use Pinoox\Component\Database\Query\Grammars\Concerns\KeepsShortTableAliases;

class PostgresGrammar extends BaseGrammar
{
    use KeepsShortTableAliases;
}
