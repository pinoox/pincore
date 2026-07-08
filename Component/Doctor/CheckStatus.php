<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    public function weight(): float
    {
        return match ($this) {
            self::Pass => 1.0,
            self::Warn => 0.5,
            self::Fail => 0.0,
            self::Skip => 0.0,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pass => '<fg=green;options=bold>✔</>',
            self::Warn => '<fg=yellow;options=bold>!</>',
            self::Fail => '<fg=red;options=bold>✖</>',
            self::Skip => '<fg=gray>-</>',
        };
    }
}
