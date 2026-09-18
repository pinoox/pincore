<?php

namespace Pinoox\Component\Cache\Opcode;

class NativeOpcodeDriver implements OpcodeDriverInterface
{
    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        if (!function_exists('opcache_invalidate')) {
            return false;
        }

        $key = (PHP_SAPI === 'cli') ? 'opcache.enable_cli' : 'opcache.enable';
        $val = ini_get($key);

        return filter_var($val, FILTER_VALIDATE_BOOL);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidate(string $file, bool $force = true): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool) @opcache_invalidate($file, $force);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): bool
    {
        if (!$this->isAvailable() || !function_exists('opcache_reset')) {
            return false;
        }

        try {
            return (bool) @opcache_reset();
        } catch (\Throwable) {
            return false;
        }
    }
}
