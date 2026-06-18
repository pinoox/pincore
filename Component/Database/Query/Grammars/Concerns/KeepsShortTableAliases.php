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

namespace Pinoox\Component\Database\Query\Grammars\Concerns;

/**
 * Prefix table names but keep SQL aliases short (p, t, u) in FROM/JOIN clauses.
 */
trait KeepsShortTableAliases
{
    protected function wrapAliasedValue($value, $prefixAlias = false)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0], $prefixAlias) . ' as ' . $this->wrapValue($segments[1]);
    }

    protected function wrapSegments($segments)
    {
        if (count($segments) > 1) {
            return collect($segments)->map(fn ($segment) => $this->wrapValue($segment))->implode('.');
        }

        return parent::wrapSegments($segments);
    }
}
