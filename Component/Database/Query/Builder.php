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

namespace Pinoox\Component\Database\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Pinoox\Component\Database\SqlAliasRewriter;

class Builder extends BaseBuilder
{
    public function selectRaw($expression, array $bindings = [])
    {
        $expression = SqlAliasRewriter::qualifyRaw((string) $expression, $this);

        return parent::selectRaw($expression, $bindings);
    }

    public function groupByRaw($sql, array $bindings = [])
    {
        $sql = SqlAliasRewriter::qualifyRaw((string) $sql, $this);

        return parent::groupByRaw($sql, $bindings);
    }

    public function orderByRaw($sql, $bindings = [])
    {
        $sql = SqlAliasRewriter::qualifyRaw((string) $sql, $this);

        return parent::orderByRaw($sql, $bindings);
    }

    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        $sql = SqlAliasRewriter::qualifyRaw((string) $sql, $this);

        return parent::havingRaw($sql, $bindings, $boolean);
    }

    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $sql = SqlAliasRewriter::qualifyRaw((string) $sql, $this);

        return parent::whereRaw($sql, $bindings, $boolean);
    }
}
