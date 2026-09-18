<?php

namespace Pinoox\Component\Cache\Opcode;

class FakeOpcodeDriver implements OpcodeDriverInterface
{
    /**
     * @var list<array{file: string, force: bool}>
     */
    public array $invalidated = [];

    public int $resetCount = 0;

    public function __construct(
        public bool $available = true,
        public bool $invalidateResult = true,
        public bool $resetResult = true,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * {@inheritDoc}
     */
    public function invalidate(string $file, bool $force = true): bool
    {
        if (!$this->available) {
            return false;
        }

        $this->invalidated[] = [
            'file' => $file,
            'force' => $force,
        ];

        return $this->invalidateResult;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): bool
    {
        if (!$this->available) {
            return false;
        }

        $this->resetCount++;

        return $this->resetResult;
    }

    public function hasInvalidated(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach ($this->invalidated as $item) {
            if (str_replace('\\', '/', $item['file']) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function invalidatedFiles(): array
    {
        return array_column($this->invalidated, 'file');
    }

    public function clear(): void
    {
        $this->invalidated = [];
        $this->resetCount = 0;
    }
}
