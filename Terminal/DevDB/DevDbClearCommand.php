<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:clear', description: 'Clear Pinoox DevDB JSON storage')]
class DevDbClearCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Clear without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force') && !$io->confirm('Clear Pinoox DevDB data?', false)) {
            $io->warning('Cancelled.');

            return Command::SUCCESS;
        }

        $this->runtime()->clear();
        $io->success('Pinoox DevDB cleared.');

        return Command::SUCCESS;
    }
}
