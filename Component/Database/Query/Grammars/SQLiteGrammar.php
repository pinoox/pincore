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

use Illuminate\Database\Query\Grammars\SQLiteGrammar as BaseGrammar;
use Pinoox\Component\Database\Query\Grammars\Concerns\KeepsShortTableAliases;

class SQLiteGrammar extends BaseGrammar
{
    use KeepsShortTableAliases;
}
