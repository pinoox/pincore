<?php

namespace Pinoox\Component\Package\Pinx;

class PinxUninstallResult
{
    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $type,
        public readonly string $package,
        public readonly ?string $themeName,
        public readonly array $steps = [],
        public readonly string $message = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function stepMessages(): array
    {
        return array_map(
            static fn (array $step) => sprintf('[%s] %s: %s', strtoupper($step['status']), $step['step'], $step['message']),
            $this->steps
        );
    }
}
