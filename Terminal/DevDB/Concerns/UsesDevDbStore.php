<?php

namespace Pinoox\Terminal\DevDB\Concerns;

use Pinoox\Component\Database\DevDB\DevDbStore;
use Pinoox\Support\SystemConfig;

trait UsesDevDbStore
{
    protected function store(): DevDbStore
    {
        $path = SystemConfig::env('DEVDB_PATH');

        return new DevDbStore(is_string($path) && $path !== '' ? $path : null);
    }

    protected function forceDevDbConnection(): void
    {
        $_ENV['DB_CONNECTION'] = 'devdb';
        $_SERVER['DB_CONNECTION'] = 'devdb';
        putenv('DB_CONNECTION=devdb');
        SystemConfig::clearCache();
    }
}

