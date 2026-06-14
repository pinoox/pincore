<?php

namespace Pinoox\Terminal\File;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\File\Concerns\ManagesCliFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file:show',
    description: 'Show details for an uploaded file',
)]
class FileShowCommand extends Terminal
{
    use ManagesCliFiles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show file record details and storage status.

Examples:
  php pinoox file:show 12
  php pinoox file:show com_pinoox_manager 12
HELP
            )
            ->addArgument('file', InputArgument::OPTIONAL, 'File id or hash_id. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveFilePackageInput($input, $output, $io, 'Show file for');
        $this->prepareFileScope($package);

        $file = $this->resolveFileInput($input, $output, $io, 'Select file');

        if ($file === null) {
            $io->error('File not found.');

            return Command::FAILURE;
        }

        $row = $this->fileRow($file);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('File #' . $file->file_id);
        $io->definitionList(
            ['Package context' => $package],
            ['Hash id' => (string) ($file->hash_id ?: '—')],
            ['Real name' => (string) ($file->file_realname ?: '—')],
            ['Stored name' => (string) ($file->file_name ?: '—')],
            ['Group' => (string) ($file->file_group ?: '—')],
            ['Extension' => (string) ($file->file_ext ?: '—')],
            ['Path' => (string) ($file->file_path ?: '—')],
            ['Size' => (string) $row['file_size_label']],
            ['Access' => (string) ($file->file_access ?: '—')],
            ['User id' => (string) ($file->user_id ?: '—')],
            ['App scope' => (string) ($file->app ?: '—')],
            ['Storage' => (string) $row['storage']],
            ['URL' => (string) ($file->file_link ?: '—')],
            ['Thumb' => (string) ($file->thumb_link ?: '—')],
            ['Created' => (string) ($row['created_at'] ?: '—')],
            ['Updated' => (string) ($row['updated_at'] ?: '—')],
            ['Metadata' => $file->file_metadata === null
                ? '—'
                : json_encode($file->file_metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        return Command::SUCCESS;
    }
}
