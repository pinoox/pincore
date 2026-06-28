<?php

namespace Pinoox\Component\Database\DevDB;

class DevDbException extends \RuntimeException
{
    public static function unsupported(string $feature, ?string $table = null): self
    {
        $scope = $table !== null && $table !== '' ? ' on table "' . $table . '"' : '';

        return new self(
            'Pinoox DevDB does not support ' . $feature . $scope . ' in v1. '
            . 'Use SQLite, MySQL, or PostgreSQL for this query.',
        );
    }
}
