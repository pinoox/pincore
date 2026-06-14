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
    name: 'file:delete',
    description: 'Delete a file record, storage asset, or both',
    aliases: ['file:remove'],
)]
class FileDeleteCommand extends Terminal
{
    use ManagesCliFiles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete a file by id or hash_id.

By default both the database row and storage files (original + thumb) are removed.

Examples:
  php pinoox file:delete 12 --force
  php pinoox file:delete 12 --db-only --force
  php pinoox file:delete 12 --storage-only --force
HELP
            )
            ->addArgument('file', InputArgument::OPTIONAL, 'File id or hash_id. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('db-only', null, InputOption::VALUE_NONE, 'Delete only the database row')
            ->addOption('storage-only', null, InputOption::VALUE_NONE, 'Delete only storage files; keep database row')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('db-only') && $input->getOption('storage-only')) {
            $io->error('Use either --db-only or --storage-only, not both.');

            return Command::FAILURE;
        }

        $package = $this->resolveFilePackageInput($input, $output, $io, 'Delete file for');
        $this->prepareFileScope($package);

        $file = $this->resolveFileInput($input, $output, $io, 'Select file to delete');

        if ($file === null) {
            $io->error('File not found.');

            return Command::FAILURE;
        }

        $mode = $this->resolveDeleteMode($input);
        $label = $file->file_realname ?: $file->file_name ?: ('#' . $file->file_id);

        if (!$input->getOption('force') && !$io->confirm(
            sprintf(
                'Delete file #%d (%s) — %s?',
                $file->file_id,
                $label,
                $this->deleteModeLabel($mode),
            ),
            false,
        )) {
            $io->warning('Delete canceled.');

            return Command::SUCCESS;
        }

        if (!$this->deleteFile($file, $mode)) {
            $io->error('Failed to delete file.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'File #%d deleted (%s).',
            $file->file_id,
            $this->deleteModeLabel($mode),
        ));

        return Command::SUCCESS;
    }
}
