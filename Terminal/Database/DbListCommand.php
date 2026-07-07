<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\Database\Concerns\ManagesCliDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:list',
    description: 'List platform database connections or app database settings',
    aliases: ['database:list', 'databases'],
)]
class DbListCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List database connections for the platform or a single app.

Examples:
  php pinoox db:list
  php pinoox db:list --test
  php pinoox db:list com_my_shop --test
  php pinoox db:list --all --test
HELP
            )
            ->addArgument('target', InputArgument::OPTIONAL, 'platform, app package, or leave empty for platform connections')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List database settings for all apps')
            ->addOption('test', 't', InputOption::VALUE_NONE, 'Test each connection and show status')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $test = (bool) $input->getOption('test');

        if ($test) {
            $this->prepareDatabaseCli();
        }

        $target = trim((string) ($input->getArgument('target') ?: ''));

        if ($input->getOption('all')) {
            $rows = DatabaseConnectionToolkit::listApps($test);

            if ($input->getOption('json')) {
                $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $io->title('App database settings');
            $io->table(
                ['Package', 'Mode', 'Prefix', 'Connection', 'Driver', 'Host', 'Database', 'Status'],
                array_map(static fn (array $row) => [
                    $row['package'],
                    $row['mode'],
                    $row['prefix'],
                    $row['connection'],
                    $row['driver'],
                    $row['host'],
                    $row['database'],
                    $row['status'],
                ], $rows),
            );
            $io->writeln(' Total: ' . count($rows));

            return Command::SUCCESS;
        }

        if ($target !== '' && $this->isAppTarget($target)) {
            try {
                $row = DatabaseConnectionToolkit::describeApp($target, $test);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            if ($input->getOption('json')) {
                $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $io->title('Database — ' . $target);
            $this->renderConnectionDetails($io, $row);
            $this->envOverrideNote($io);

            return Command::SUCCESS;
        }

        $rows = DatabaseConnectionToolkit::listPlatformConnections($test);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $io->warning('No platform connections are configured.');

            return Command::SUCCESS;
        }

        $io->title('Platform database connections');
        $io->table(
            ['Name', 'Default', 'Driver', 'Host', 'Database', 'Prefix', 'Status'],
            array_map(static fn (array $row) => [
                $row['name'],
                $row['default'],
                $row['driver'],
                $row['host'],
                $row['database'],
                $row['prefix'],
                $row['status'],
            ], $rows),
        );
        $io->writeln(' Total: ' . count($rows));
        $this->envOverrideNote($io);

        return Command::SUCCESS;
    }
}
