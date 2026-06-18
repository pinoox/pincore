<?php

namespace Pinoox\Terminal\Pinion;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Pinion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinion:clean',
    description: 'Clean expired or abort a Pinion upload session',
)]
class PinionCleanCommand extends Terminal
{
    protected function configure(): void
    {
        $this->addOption('abort', null, InputOption::VALUE_REQUIRED, 'Abort a specific upload id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $abortId = $input->getOption('abort');

        if (is_string($abortId) && $abortId !== '') {
            if (Pinion::abort($abortId)) {
                $io->success('Pinion session aborted: ' . $abortId);

                return Command::SUCCESS;
            }

            $io->error('Unable to abort Pinion session: ' . $abortId);

            return Command::FAILURE;
        }

        $removed = Pinion::cleanExpired();
        $io->success('Removed ' . $removed . ' expired Pinion session(s).');

        return Command::SUCCESS;
    }
}
