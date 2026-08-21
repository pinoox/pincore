<?php

namespace Pinoox\Component\Package\Pinx;

final class PlatformUpdateResult
{
    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     * @param list<string> $apps
     * @param array{name: string, code: ?int} $fromVersion
     * @param array{name: string, code: ?int} $toVersion
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $steps = [],
        public readonly array $apps = [],
        public readonly array $fromVersion = ['name' => '', 'code' => null],
        public readonly array $toVersion = ['name' => '', 'code' => null],
    ) {
    }

    /**
     * @return list<string>
     */
    public function stepMessages(): array
    {
        return array_map(
            static fn (array $step) => sprintf('[%s] %s: %s', strtoupper($step['status']), $step['step'], $step['message']),
            $this->steps,
        );
    }
}
