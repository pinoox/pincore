<?php

namespace Pinoox\Component\Deps;

final readonly class DependencyRunResult
{
    /**
     * @param list<string> $outputLines
     * @param list<string> $warnings
     */
    public function __construct(
        public DependencyTarget $target,
        public string $action,
        public string $commandLine,
        public int $exitCode,
        public float $durationSeconds,
        public array $outputLines = [],
        public array $warnings = [],
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            target: $this->target,
            action: $this->action,
            commandLine: $this->commandLine,
            exitCode: $this->exitCode,
            durationSeconds: $this->durationSeconds,
            outputLines: $this->outputLines,
            warnings: $warnings,
        );
    }
}
