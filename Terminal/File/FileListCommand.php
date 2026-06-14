<?php

namespace Pinoox\Terminal\File;

use Pinoox\Component\Terminal;
use Pinoox\Model\FileModel;
use Pinoox\Terminal\File\Concerns\ManagesCliFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file:list',
    description: 'List uploaded files for an app or platform',
    aliases: ['files'],
)]
class FileListCommand extends Terminal
{
    use ManagesCliFiles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List files in the current file-storage scope.

Examples:
  php pinoox file:list
  php pinoox file:list com_pinoox_manager
  php pinoox file:list --group=avatar --missing
  php pinoox file:list --user=admin --ext=jpg
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Filter by user id, username, or email')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Filter by file_group')
            ->addOption('access', 'a', InputOption::VALUE_REQUIRED, 'Filter by file_access (public/private)')
            ->addOption('ext', null, InputOption::VALUE_REQUIRED, 'Filter by file extension')
            ->addOption('missing', 'm', InputOption::VALUE_NONE, 'Show only rows whose storage file is missing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveFilePackage($input, $output, $io, 'List files for');
        $this->prepareFileScope($package);

        $query = FileModel::query()->orderByDesc('file_id');

        $userFilter = $input->getOption('user');
        if (is_string($userFilter) && $userFilter !== '') {
            $user = $this->resolveUser($userFilter);
            if ($user === null) {
                $io->error('User not found: ' . $userFilter);

                return Command::FAILURE;
            }

            $query->where('user_id', $user->user_id);
        }

        $group = $input->getOption('group');
        if (is_string($group) && $group !== '') {
            $query->where('file_group', $group);
        }

        $access = $input->getOption('access');
        if (is_string($access) && $access !== '') {
            $query->where('file_access', $access);
        }

        $ext = $input->getOption('ext');
        if (is_string($ext) && $ext !== '') {
            $query->where('file_ext', ltrim(strtolower($ext), '.'));
        }

        $files = $query->get();

        if ($input->getOption('missing')) {
            $files = $files->filter(fn (FileModel $file) => !$this->fileExistsOnStorage($file))->values();
        }

        if ($input->getOption('json')) {
            $rows = $files->map(fn (FileModel $file) => $this->fileRow($file))->values()->all();
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($files->isEmpty()) {
            $io->warning('No files found for package: ' . $package);

            return Command::SUCCESS;
        }

        $io->title('Files — ' . $package);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Group', 'Ext', 'Size', 'Access', 'User', 'Storage']);
        foreach ($files as $file) {
            $table->addRow([
                $file->file_id,
                $file->file_realname ?: $file->file_name,
                $file->file_group ?: '—',
                $file->file_ext ?: '—',
                $this->formatFileSize($file->file_size),
                $file->file_access ?: '—',
                $file->user_id ?: '—',
                $this->storageStatusLabel($file),
            ]);
        }
        $table->render();

        $io->text('Total: ' . $files->count());

        return Command::SUCCESS;
    }
}
