<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:export', description: 'Export Pinoox DevDB as JSON')]
class DevDbExportCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Optional output JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $json = json_encode($this->runtime()->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $file = $input->getArgument('file');

        if (is_string($file) && $file !== '') {
            $dir = dirname($file);
            if ($dir !== '.' && !is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($file, $json . PHP_EOL);
            $io->success('DevDB export written: ' . $file);

            return Command::SUCCESS;
        }

        $io->writeln($json);

        return Command::SUCCESS;
    }
}
