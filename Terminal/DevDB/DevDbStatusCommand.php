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

#[AsCommand(name: 'devdb:status', description: 'Show Pinoox DevDB status')]
class DevDbStatusCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $status = $this->runtime()->status();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Pinoox DevDB');
        $io->definitionList(
            ['Path' => $status['path']],
            ['Engine' => $status['engine'] ?? 'json'],
            ['Database' => $status['database'] ?? '-'],
            ['Schema version' => (string) $status['schema_version']],
            ['Tables' => (string) $status['table_count']],
            ['Migrations' => (string) $status['migration_count']],
        );

        $rows = array_map(static fn ($table) => [
            $table['table'],
            (string) $table['columns'],
            (string) $table['rows'],
            $table['primary_key'] ?? '-',
        ], $status['tables']);

        if ($rows !== []) {
            $io->table(['Table', 'Columns', 'Rows', 'Primary key'], $rows);
        }

        return Command::SUCCESS;
    }
}
