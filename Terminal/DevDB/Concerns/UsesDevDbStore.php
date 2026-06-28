<?php

namespace Pinoox\Terminal\DevDB\Concerns;

use Pinoox\Component\Database\DevDB\DevDbStore;
use Pinoox\Component\Database\DevDB\DevDbRuntime;
use Pinoox\Support\SystemConfig;

trait UsesDevDbStore
{
    protected function store(): DevDbStore
    {
        return $this->runtime()->store();
    }

    protected function runtime(): DevDbRuntime
    {
        return new DevDbRuntime();
    }

    protected function forceDevDbConnection(): void
    {
        $_ENV['DB_CONNECTION'] = 'devdb';
        $_SERVER['DB_CONNECTION'] = 'devdb';
        putenv('DB_CONNECTION=devdb');
        SystemConfig::clearCache();
    }
}
