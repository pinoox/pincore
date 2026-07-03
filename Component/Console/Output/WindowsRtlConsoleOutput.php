<?php

namespace Pinoox\Component\Console\Output;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class WindowsRtlConsoleOutput extends ConsoleOutput
{
    public function __construct(
        int $verbosity = self::VERBOSITY_NORMAL,
        ?bool $decorated = null,
        ?OutputFormatterInterface $formatter = null
    ) {
        parent::__construct($verbosity, $decorated, $formatter);

        $this->setErrorOutput(new WindowsRtlStreamOutput(
            fopen('php://stderr', 'w') ?: STDERR,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter()
        ));
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(RtlText::toConsoleVisual($message), $newline);
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        parent::setErrorOutput($error);
    }
}
