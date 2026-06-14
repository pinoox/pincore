<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Terminal\Database\Concerns\ManagesCliDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:show',
    description: 'Show database connection details',
    aliases: ['database:show'],
)]
class DbShowCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show database connection details for platform or an app.

Examples:
  php pinoox db:show mysql
  php pinoox db:show com_my_shop
HELP
            )
            ->addArgument('target', InputArgument::OPTIONAL, 'Platform connection name or app package')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareDatabaseCli();

        $target = trim((string) ($input->getArgument('target') ?: ''));

        if ($target === '' && $input->isInteractive()) {
            $target = $this->resolveDatabaseTarget($input, $output, $io, 'Show database for');
        }

        try {
            if ($this->isAppTarget($target)) {
                $row = DatabaseConnectionToolkit::describeApp($target, test: true);
            } elseif ($this->isPlatformTarget($target) || $target === '') {
                $connection = $this->resolvePlatformConnectionTarget($input, $output, $io);
                $row = DatabaseConnectionToolkit::describePlatformConnection($connection, test: true);
            } else {
                $row = DatabaseConnectionToolkit::describePlatformConnection($target, test: true);
            }
        } catch (\InvalidArgumentException $e) {
            if (AppEngine::exists($target)) {
                $row = DatabaseConnectionToolkit::describeApp($target, test: true);
            } else {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $title = isset($row['package']) ? 'Database — ' . $row['package'] : 'Connection — ' . ($row['name'] ?? $target);
        $io->title($title);
        $this->renderConnectionDetails($io, $row);
        $this->envOverrideNote($io);

        return Command::SUCCESS;
    }
}
