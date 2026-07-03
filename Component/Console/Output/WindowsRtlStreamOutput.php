<?php

namespace Pinoox\Component\Console\Output;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\StreamOutput;

class WindowsRtlStreamOutput extends StreamOutput
{
    public function __construct($stream, int $verbosity = self::VERBOSITY_NORMAL, ?bool $decorated = null, ?OutputFormatterInterface $formatter = null)
    {
        parent::__construct($stream, $verbosity, $decorated, $formatter);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(RtlText::toConsoleVisual($message), $newline);
    }
}
