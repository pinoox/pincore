<?php

namespace Pinoox\Component\Database\DevDB;

class DevDbException extends \RuntimeException
{
    public static function unsupported(string $feature): self
    {
        return new self(
            'Pinoox DevDB does not support ' . $feature . ' in v1. '
            . 'Use SQLite, MySQL, or PostgreSQL for this query.',
        );
    }
}

