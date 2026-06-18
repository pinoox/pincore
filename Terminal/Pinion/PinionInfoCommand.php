<?php

namespace Pinoox\Terminal\Pinion;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Pinion;
use Pinoox\Terminal\Pinion\Concerns\ManagesCliPinion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinion:info',
    description: 'Show a Pinion upload session',
)]
class PinionInfoCommand extends Terminal
{
    use ManagesCliPinion;

    protected function configure(): void
    {
        $this
            ->addArgument('upload_id', InputArgument::REQUIRED, 'Pinion session id')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $uploadId = (string) $input->getArgument('upload_id');
        $session = Pinion::status($uploadId);

        if ($session === null) {
            $io->error('Pinion session not found: ' . $uploadId);

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Pinion — ' . $session->filename);
        $this->renderPinionSession($io, $session);

        return Command::SUCCESS;
    }
}
