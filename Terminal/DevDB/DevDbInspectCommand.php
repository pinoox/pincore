<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:inspect', description: 'Inspect a Pinoox DevDB table')]
class DevDbInspectCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'Table name')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to show', 10)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        try {
            $inspect = $this->runtime()->inspectTable(
                (string) $input->getArgument('table'),
                (int) $input->getOption('limit'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode($inspect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('DevDB table: ' . $inspect['table']);
        $io->definitionList(
            ['Rows' => (string) $inspect['row_count']],
            ['Primary key' => $inspect['primary_key'] ?? '-'],
        );
        $io->section('Columns');
        $io->table(['Column', 'Type', 'Nullable', 'Default'], array_map(static fn ($name, $column) => [
            $name,
            $column['type'] ?? 'string',
            !empty($column['nullable']) ? 'yes' : 'no',
            $column['default'] ?? '-',
        ], array_keys($inspect['columns']), $inspect['columns']));

        if ($inspect['rows'] !== []) {
            $columns = array_keys($inspect['rows'][0]);
            $io->section('Rows');
            $io->table($columns, array_map(static fn ($row) => array_map(
                static fn ($column) => is_scalar($row[$column] ?? null) ? (string) ($row[$column] ?? '') : json_encode($row[$column] ?? null),
                $columns,
            ), $inspect['rows']));
        }

        return Command::SUCCESS;
    }
}
