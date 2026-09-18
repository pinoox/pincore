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

namespace Pinoox\Component\Database\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Pinoox prefixes physical table names on the connection while Eloquent
 * qualifies columns with the logical FROM table (e.g. products.updated_at).
 * Normalize columns through the model before they reach the query builder.
 *
 * @see \Pinoox\Component\Database\Model::qualifyColumn()
 */
class Builder extends EloquentBuilder
{
    protected function normalizeColumn(mixed $column): mixed
    {
        if (!is_string($column) || str_contains($column, '->')) {
            return $column;
        }

        return $this->qualifyColumn($column);
    }

    /**
     * @param  \Closure|string|array|\Illuminate\Contracts\Database\Query\Expression  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column instanceof Closure && is_null($operator)) {
            return parent::where($column, $operator, $value, $boolean);
        }

        if (is_string($column)) {
            return parent::where($this->normalizeColumn($column), $operator, $value, $boolean);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * @param  string|array<int, string>|\Illuminate\Contracts\Database\Query\Expression  $columns
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        if (is_array($columns)) {
            $columns = array_map(fn ($column) => $this->normalizeColumn($column), $columns);
        } else {
            $columns = $this->normalizeColumn($columns);
        }

        return parent::whereNull($columns, $boolean, $not);
    }

    /**
     * @param  string|array<int, string>|\Illuminate\Contracts\Database\Query\Expression  $columns
     * @param  string  $boolean
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereNotNull($columns, $boolean = 'and')
    {
        if (is_array($columns)) {
            $columns = array_map(fn ($column) => $this->normalizeColumn($column), $columns);
        } else {
            $columns = $this->normalizeColumn($columns);
        }

        return parent::whereNotNull($columns, $boolean);
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $this->query->whereIn(
            $this->normalizeColumn($column),
            $values,
            $boolean,
            $not,
        );

        // Query Builder returns itself; keep this Eloquent builder so first() hydrates models.
        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        $this->query->whereNotIn(
            $this->normalizeColumn($column),
            $values,
            $boolean,
        );

        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereIntegerInRaw($column, $values, $boolean = 'and', $not = false)
    {
        $this->query->whereIntegerInRaw(
            $this->normalizeColumn($column),
            $values,
            $boolean,
            $not,
        );

        return $this;
    }

    /**
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function whereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        $this->query->whereIntegerNotInRaw(
            $this->normalizeColumn($column),
            $values,
            $boolean,
        );

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values): int
    {
        $values = $this->addUpdatedAtColumn($values);

        return (int) $this->toBase()->update($this->normalizeUpdateValues($values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function addUpdatedAtColumn(array $values): array
    {
        return $this->normalizeUpdateValues(parent::addUpdatedAtColumn($values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalizeUpdateValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $column => $value) {
            $key = is_string($column) ? $this->normalizeColumn($column) : $column;
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
