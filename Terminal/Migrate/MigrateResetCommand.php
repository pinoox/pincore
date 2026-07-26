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
    name: 'migrate:reset',
    description: 'Rollback all migration batches via down()',
    aliases: ['mg:reset'],
)]
class MigrateResetCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Rolls back every executed migration batch using each migration's down() method.

For a hard drop of tables (without relying on down()), use migrate:drop.
To drop tables and migrate again, use migrate:fresh.

Examples:
  php pinoox migrate:reset
  php pinoox migrate:reset com_my_shop
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
            sprintf('Rollback ALL migration batches for "%s"?', $package),
            false,
        )) {
            $io->warning('Reset cancelled.');

            return Command::SUCCESS;
        }

        try {
            $messages = (new Migrator($package))->reset();
            foreach ($messages as $message) {
                $io->writeln((string) $message);
            }
            $io->success('Migration reset finished.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
