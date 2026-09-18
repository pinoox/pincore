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

use Illuminate\Database\SQLiteConnection as BaseConnection;
use Pinoox\Component\Database\Connections\Concerns\CreatesQueryGrammar;
use Pinoox\Component\Database\Query\Builder;
use Pinoox\Component\Database\Query\Grammars\SQLiteGrammar;

class SQLiteConnection extends BaseConnection
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
     * @return \Illuminate\Database\Query\Grammars\SQLiteGrammar
     */
    protected function getDefaultQueryGrammar(): \Illuminate\Database\Query\Grammars\SQLiteGrammar
    {
        /** @var \Illuminate\Database\Query\Grammars\SQLiteGrammar $grammar */
        $grammar = $this->createQueryGrammar();

        return $grammar;
    }

    public function statement($query, $bindings = [])
    {
        $sql = trim((string) $query);

        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*(0|1)/i', $sql, $matches) === 1) {
            return parent::statement('PRAGMA foreign_keys = ' . ((string) $matches[1] === '1' ? 'ON' : 'OFF'));
        }

        return parent::statement($query, $bindings);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (preg_match('/information_schema\.tables/i', (string) $query) === 1) {
            $table = $this->extractInformationSchemaTableName($query, $bindings);

            if ($table === '') {
                return [];
            }

            return parent::select(
                "SELECT 1 AS found FROM sqlite_master WHERE type IN ('table', 'view') AND name = ? LIMIT 1",
                [$table],
                $useReadPdo,
            );
        }

        return parent::select($query, $bindings, $useReadPdo);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function extractInformationSchemaTableName(string $query, array $bindings): string
    {
        if (isset($bindings[1]) && is_scalar($bindings[1])) {
            return (string) $bindings[1];
        }

        if (isset($bindings[0]) && is_scalar($bindings[0]) && !str_contains((string) $bindings[0], DIRECTORY_SEPARATOR)) {
            return (string) $bindings[0];
        }

        if (preg_match('/table_name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $query, $matches) === 1) {
            return (string) $matches[1];
        }

        return '';
    }

    protected function queryGrammarClass(): string
    {
        return SQLiteGrammar::class;
    }
}
