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
    name: 'file:update',
    description: 'Update file metadata, group, or access',
)]
class FileUpdateCommand extends Terminal
{
    use ManagesCliFiles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Update file table fields (does not rename storage files).

Examples:
  php pinoox file:update 12 --group=avatar --access=public
  php pinoox file:update 12 --name="profile.jpg"
  php pinoox file:update 12 --meta disk=local --metadata='{"tag":"avatar"}'
HELP
            )
            ->addArgument('file', InputArgument::OPTIONAL, 'File id or hash_id. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'file_group')
            ->addOption('access', 'a', InputOption::VALUE_REQUIRED, 'file_access: public or private')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'file_realname')
            ->addOption('meta', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set metadata key=value (repeatable, merged)')
            ->addOption('metadata', 'm', InputOption::VALUE_REQUIRED, 'Metadata as JSON object (merged)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveFilePackageInput($input, $output, $io, 'Update file for');
        $this->prepareFileScope($package);

        $file = $this->resolveFileInput($input, $output, $io, 'Select file to update');

        if ($file === null) {
            $io->error('File not found.');

            return Command::FAILURE;
        }

        $changes = [];

        try {
            $group = $input->getOption('group');
            if (is_string($group) && $group !== '') {
                $file->file_group = $group;
                $changes[] = 'group';
            }

            $access = $input->getOption('access');
            if (is_string($access) && $access !== '') {
                if (!in_array(strtolower($access), ['public', 'private'], true)) {
                    throw new \InvalidArgumentException('Access must be public or private.');
                }

                $file->file_access = strtolower($access);
                $changes[] = 'access';
            }

            $name = $input->getOption('name');
            if (is_string($name) && $name !== '') {
                $file->file_realname = $name;
                $changes[] = 'name';
            }

            $metadata = is_array($file->file_metadata) ? $file->file_metadata : [];
            $metaChanged = false;

            /** @var list<string> $metaValues */
            $metaValues = $input->getOption('meta');
            foreach ($metaValues as $pair) {
                if (!is_string($pair) || !str_contains($pair, '=')) {
                    throw new \InvalidArgumentException('Invalid --meta value (expected key=value): ' . (string) $pair);
                }

                [$key, $value] = explode('=', $pair, 2);
                $key = trim($key);
                if ($key === '') {
                    throw new \InvalidArgumentException('Invalid --meta key.');
                }

                $metadata[$key] = $value === '' ? null : $value;
                $metaChanged = true;
            }

            $metadataJson = $input->getOption('metadata');
            if (is_string($metadataJson) && $metadataJson !== '') {
                $metadata = array_merge($metadata, $this->parseFileMetadataJson($metadataJson));
                $metaChanged = true;
            }

            if ($metaChanged) {
                $file->file_metadata = $this->mergeFileMetadata(is_array($file->file_metadata) ? $file->file_metadata : [], $metadata);
                $changes[] = 'metadata';
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($changes === []) {
            $io->warning('No changes provided.');

            return Command::SUCCESS;
        }

        $file->save();

        $io->success(sprintf('File #%d updated (%s).', $file->file_id, implode(', ', $changes)));
        $io->definitionList(
            ['Group' => (string) ($file->file_group ?: '—')],
            ['Access' => (string) ($file->file_access ?: '—')],
            ['Real name' => (string) ($file->file_realname ?: '—')],
        );

        return Command::SUCCESS;
    }
}
