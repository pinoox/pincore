<?php

namespace Pinoox\Terminal\Cache;

use Pinoox\Component\Cache\OpcodeCache;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\OpcodeCache as OpcodeCachePortal;
use Pinoox\Portal\Path;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'opcache:clear',
    description: 'Targeted OPcache invalidation for apps, core, or explicit full reset',
    aliases: ['opcache:invalidate', 'oc'],
)]
class OpcodeCacheCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Invalidates compiled opcode for apps, files, or core in OPcache without clearing unrelated cache.

Examples:

  php pinoox opcache:clear welcome
  php pinoox opcache:clear all
  php pinoox opcache:clear --core
  php pinoox opcache:clear --file=apps/welcome/Controller/HomeController.php
  php pinoox opcache:clear --reset
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package to invalidate (or "all")')
            ->addOption('core', null, InputOption::VALUE_NONE, 'Invalidate all Pinoox core framework files')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Invalidate a specific PHP file')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Explicitly perform a full opcache_reset()')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force invalidation even if timestamp is older');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        if (!OpcodeCachePortal::isAvailable()) {
            $io->warning('OPcache is not enabled for CLI runtime (opcache.enable_cli is 0 or extension is unavailable).');
        }

        if ($input->getOption('reset')) {
            if (!$this->confirm('Are you sure you want to perform a full OPcache reset?', $input, $output)) {
                $io->note('OPcache reset cancelled.');
                return Command::SUCCESS;
            }

            $ok = OpcodeCachePortal::reset();
            if ($ok) {
                $io->success('OPcache reset successfully.');
            } else {
                $io->error('Failed to reset OPcache (not available or disabled).');
            }

            return Command::SUCCESS;
        }

        $force = (bool) ($input->getOption('force') ?: true);

        // Targeted file
        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $ok = OpcodeCachePortal::invalidateFile($file, $force);
            if ($ok) {
                $io->success(sprintf('Invalidated opcode for file: %s', $file));
            } else {
                $io->warning(sprintf('Could not invalidate file (not found or OPcache unavailable): %s', $file));
            }

            return Command::SUCCESS;
        }

        // Targeted core
        if ($input->getOption('core')) {
            $count = OpcodeCachePortal::invalidateCore($force);
            $io->success(sprintf('Invalidated %d core PHP file(s) in OPcache.', $count));

            return Command::SUCCESS;
        }

        // Targeted app(s)
        $package = $input->getArgument('package');
        if (empty($package)) {
            $package = $this->resolvePackageRequired($input, $output, $io, [
                'allowAll' => true,
                'default' => 'all',
                'sectionTitle' => 'Invalidate OPcache for',
            ]);
        }

        $packages = ($package === 'all') ? array_keys(AppEngine::all()) : [$package];
        $rows = [];

        foreach ($packages as $pkg) {
            $count = OpcodeCachePortal::invalidateApp($pkg, $force);
            $rows[] = [$pkg, $count . ' file(s)'];
        }

        $io->title('Targeted OPcache Invalidation');
        $io->table(['Package', 'Invalidated'], $rows);

        return Command::SUCCESS;
    }
}
