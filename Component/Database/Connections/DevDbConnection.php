<?php

namespace Pinoox\Component\Database\Connections;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as SchemaGrammar;
use Pinoox\Component\Database\DevDB\DevDbException;
use Pinoox\Component\Database\DevDB\DevDbQueryBuilder;
use Pinoox\Component\Database\DevDB\DevDbSchemaBuilder;
use Pinoox\Component\Database\DevDB\DevDbStore;

class DevDbConnection extends Connection
{
    private DevDbStore $store;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct(null, $database ?: 'devdb', $tablePrefix, $config);

        $this->store = new DevDbStore($config['path'] ?? null);
        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
        $this->useDefaultSchemaGrammar();
    }

    public function devDbStore(): DevDbStore
    {
        return $this->store;
    }

    public function getDriverName()
    {
        return 'devdb';
    }

    public function getPdo()
    {
        return null;
    }

    public function query()
    {
        return new DevDbQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    public function getSchemaBuilder()
    {
        return new DevDbSchemaBuilder($this);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (preg_match('/information_schema\.tables/i', (string) $query) === 1) {
            $table = (string) ($bindings[1] ?? '');

            return $this->store->hasTable($table) ? [(object) ['found' => 1]] : [];
        }

        throw DevDbException::unsupported('raw select SQL');
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return $records[0] ?? null;
    }

    public function statement($query, $bindings = [])
    {
        $sql = trim((string) $query);

        if (preg_match('/^(SET\s+FOREIGN_KEY_CHECKS|PRAGMA\s+foreign_keys)/i', $sql) === 1) {
            return true;
        }

        throw DevDbException::unsupported('raw statement SQL');
    }

    public function beginTransaction()
    {
        $this->store->beginTransaction();
    }

    public function commit()
    {
        $this->store->commitTransaction();
    }

    public function rollBack($toLevel = null)
    {
        $this->store->rollbackTransaction();
    }

    public function transactionLevel()
    {
        return $this->store->transactionLevel();
    }

    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = new QueryGrammar($this);
    }

    public function useDefaultSchemaGrammar()
    {
        $this->schemaGrammar = new SchemaGrammar($this);
    }

    public function useDefaultPostProcessor()
    {
        $this->postProcessor = new Processor();
    }
}
