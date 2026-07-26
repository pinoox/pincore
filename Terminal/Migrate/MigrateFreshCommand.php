<?php

namespace Pinoox\Terminal\Migrate;

use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate:fresh',
    description: 'Drop package tables and re-run all migrations',
    aliases: ['mg:fresh'],
)]
class MigrateFreshCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Drops package tables (hard drop), clears migration history, then runs all
migrations from scratch.

Examples:
  php pinoox migrate:fresh
  php pinoox migrate:fresh com_my_shop --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackage($input, $output, $io);

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Drop tables for "%s" and re-run all migrations?', $package),
            false,
        )) {
            $io->warning('Fresh migrate cancelled.');

            return Command::SUCCESS;
        }

        try {
            $messages = (new Migrator($package))->fresh();
            foreach ($messages as $message) {
                $io->writeln((string) $message);
            }
            $io->success('Fresh migration finished.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
