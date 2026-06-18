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

use Illuminate\Database\Query\Grammars\MySqlGrammar as BaseGrammar;
use Pinoox\Component\Database\Query\Grammars\Concerns\KeepsShortTableAliases;

class MySqlGrammar extends BaseGrammar
{
    use KeepsShortTableAliases;
}
