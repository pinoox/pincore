<?php

namespace Pinoox\Component\Cache\Opcode;

interface OpcodeDriverInterface
{
    /**
     * Determine if OPcache is supported and enabled in current runtime.
     */
    public function isAvailable(): bool;

    /**
     * Invalidate cached opcode for a specific file.
     *
     * @param string $file Absolute or relative file path
     * @param bool $force When true, invalidates regardless of file modification time
     */
    public function invalidate(string $file, bool $force = true): bool;

    /**
     * Reset the entire OPcache buffer.
     */
    public function reset(): bool;
}
