<?php

namespace Pinoox\Terminal\Storage;

use Pinoox\Component\Storage\StorageSetup;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'storage:setup',
    description: 'Ensure storage root and local disks match their protect (lock/unlock) settings',
    aliases: ['storage:lock', 'storage:unlock'],
)]
class StorageSetupCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Prepare storage web protection from filesystems.disks.*.protect:

  php pinoox storage:setup
  php pinoox storage:lock
  php pinoox storage:unlock
  php pinoox storage:lock local
  php pinoox storage:unlock public

protect values: lock | unlock (aliases: deny/allow, private/public, …)
HELP
            )
            ->addArgument('disk', InputArgument::OPTIONAL, 'Disk name (local, public, …)')
            ->addOption('lock', null, InputOption::VALUE_NONE, 'Force lock (deny web)')
            ->addOption('unlock', null, InputOption::VALUE_NONE, 'Force unlock (allow web)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $invoked = $this->invokedName();
        $disk = $input->getArgument('disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : null;

        $forceLock = $input->getOption('lock') || $invoked === 'storage:lock';
        $forceUnlock = $input->getOption('unlock') || $invoked === 'storage:unlock';

        if ($disk !== null) {
            $force = null;
            if ($forceLock && !$forceUnlock) {
                $force = StorageSetup::PROTECT_LOCK;
            } elseif ($forceUnlock && !$forceLock) {
                $force = StorageSetup::PROTECT_UNLOCK;
            }

            $ok = StorageSetup::ensureDisk($disk, $force);
            $root = StorageSetup::diskRoot($disk) ?? '(missing)';
            $mode = $force ?? StorageSetup::normalizeProtect(
                config('filesystems.disks.' . $disk . '.protect')
            );

            $io->{$ok ? 'success' : 'error'}($ok
                ? sprintf('Disk [%s] protect=%s → %s', $disk, $mode, $root)
                : sprintf('Failed to protect disk [%s]', $disk));

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if ($forceLock && !$forceUnlock) {
            $ok = StorageSetup::lock();
            $io->{$ok ? 'success' : 'error'}($ok
                ? 'Storage root locked: ' . StorageSetup::storageRoot()
                : 'Failed to lock storage root.');

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if ($forceUnlock && !$forceLock) {
            $ok = StorageSetup::unlockPublic();
            $io->{$ok ? 'success' : 'error'}($ok
                ? 'Public storage unlocked: ' . StorageSetup::publicRoot()
                : 'Failed to unlock public storage.');

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        $ok = StorageSetup::ensure();
        if ($ok) {
            $lines = [
                'Storage ready.',
                'Root (denied): ' . StorageSetup::storageRoot(),
            ];
            foreach (StorageSetup::localDiskNames() as $name) {
                $protect = StorageSetup::normalizeProtect(
                    config('filesystems.disks.' . $name . '.protect')
                );
                $lines[] = sprintf('%s (%s): %s', $name, $protect, StorageSetup::diskRoot($name) ?? '-');
            }
            $io->success($lines);
        } else {
            $io->error('Storage setup failed.');
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function invokedName(): string
    {
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, 'storage:')) {
                return $arg;
            }
        }

        return (string) $this->getName();
    }
}
