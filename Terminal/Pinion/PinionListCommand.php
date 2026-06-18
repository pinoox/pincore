<?php

namespace Pinoox\Terminal\Pinion;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Pinion;
use Pinoox\Terminal\Pinion\Concerns\ManagesCliPinion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinion:list',
    description: 'List Pinion upload sessions',
)]
class PinionListCommand extends Terminal
{
    use ManagesCliPinion;

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter: pending, completed, aborted')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $status = $input->getOption('status');
        $status = is_string($status) && $status !== '' ? $status : null;
        $sessions = Pinion::list($status);

        if ($input->getOption('json')) {
            $rows = array_map(static fn ($session) => $session->toArray(), $sessions);
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($sessions === []) {
            $io->warning('No Pinion sessions found.');

            return Command::SUCCESS;
        }

        $io->title('Pinion uploads');
        $table = new Table($output);
        $table->setHeaders(['ID', 'Filename', 'Status', 'Progress', 'Chunks', 'Expires']);
        foreach ($sessions as $session) {
            $table->addRow([
                substr($session->id, 0, 8) . '…',
                $session->filename,
                $session->status,
                $this->formatPinionProgress($session),
                count($session->received_indexes) . '/' . $session->total_chunks,
                date('Y-m-d H:i', $session->expires_at),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
