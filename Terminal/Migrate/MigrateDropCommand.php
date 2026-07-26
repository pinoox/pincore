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
    name: 'migrate:drop',
    description: 'Drop package tables created by migrations and clear history',
    aliases: ['mg:drop'],
)]
class MigrateDropCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Hard-drops tables detected from migration files and clears migration history
for the selected package. The platform history table itself is never dropped.

Examples:
  php pinoox migrate:drop
  php pinoox migrate:drop com_my_shop --force
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
            sprintf('Drop ALL tables for "%s" and clear migration history?', $package),
            false,
        )) {
            $io->warning('Drop cancelled.');

            return Command::SUCCESS;
        }

        try {
            $result = (new Migrator($package))->dropTables(true);
            foreach ($result['messages'] as $message) {
                $io->writeln((string) $message);
            }
            $io->success(sprintf(
                'Drop finished. Tables removed: %d.',
                count($result['dropped']),
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
