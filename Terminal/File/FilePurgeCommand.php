<?php

namespace Pinoox\Terminal\File;

use Pinoox\Component\Terminal;
use Pinoox\Model\FileModel;
use Pinoox\Terminal\File\Concerns\ManagesCliFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file:purge',
    description: 'Clean up missing or selected file records',
    aliases: ['file:cleanup'],
)]
class FilePurgeCommand extends Terminal
{
    use ManagesCliFiles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Clean up file records in the current scope.

Examples:
  php pinoox file:purge --missing --db-only --force
  php pinoox file:purge --missing --force
  php pinoox file:purge --group=temp --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('missing', 'm', InputOption::VALUE_NONE, 'Target rows whose storage file is missing')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Limit purge to file_group')
            ->addOption('db-only', null, InputOption::VALUE_NONE, 'Delete only database rows')
            ->addOption('storage-only', null, InputOption::VALUE_NONE, 'Delete only storage files; keep database rows')
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

        if (!$input->getOption('missing') && !$input->getOption('group')) {
            $io->error('Specify --missing and/or --group to select files for purge.');

            return Command::FAILURE;
        }

        $package = $this->resolveFilePackage($input, $output, $io, 'Purge files for');
        $this->prepareFileScope($package);

        $query = FileModel::query()->orderBy('file_id');

        $group = $input->getOption('group');
        if (is_string($group) && $group !== '') {
            $query->where('file_group', $group);
        }

        $files = $query->get();

        if ($input->getOption('missing')) {
            $files = $files->filter(fn (FileModel $file) => !$this->fileExistsOnStorage($file))->values();
        }

        if ($files->isEmpty()) {
            $io->warning('No matching files to purge.');

            return Command::SUCCESS;
        }

        $mode = $this->resolveDeleteMode($input);

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Purge %d file(s) — %s?', $files->count(), $this->deleteModeLabel($mode)),
            false,
        )) {
            $io->warning('Purge canceled.');

            return Command::SUCCESS;
        }

        $deleted = 0;

        foreach ($files as $file) {
            if ($this->deleteFile($file, $mode)) {
                $deleted++;
            }
        }

        $io->success(sprintf('Purged %d file(s) (%s).', $deleted, $this->deleteModeLabel($mode)));

        return Command::SUCCESS;
    }
}
