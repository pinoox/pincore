<?php

namespace Pinoox\Terminal\Pincore;

use Pinoox\Component\Cache\AppCacheManager;
use Pinoox\Component\Terminal;
use Pinoox\Portal\OpcodeCache;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cache:clear',
    description: 'Clear app runtime cache (routes, API, Twig, GraphQL, Pinker)',
    aliases: ['cc'],
)]

class CacheClearCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Removes cached files from pinker/apps/{package}/cache/.

Examples:

  php pinoox cache:clear

  php pinoox cache:clear com_my_shop

  php pinoox cache:clear com_my_shop --only=twig

HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(allowAll: true))
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Clear only these stores: routes, api, boot, twig, graphql, pinker')
            ->addOption('opcache', null, InputOption::VALUE_NONE, 'Invalidate OPcache for the selected package(s)')
            ->addOption('opcache-reset', null, InputOption::VALUE_NONE, 'Explicitly perform a full OPcache reset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'allowAll' => true,
            'default' => 'all',
            'sectionTitle' => 'Clear cache for',
        ]);
        $only = array_values(array_filter(array_map('trim', $input->getOption('only') ?? [])));

        AppCacheManager::clear(
            $package === 'all' ? null : $package,
            $only === [] ? null : $only,
        );

        $io->success('App cache cleared.');

        if ($input->getOption('opcache')) {
            $packages = ($package === 'all') ? AppCacheManager::packages(null) : [$package];
            $count = 0;
            foreach ($packages as $pkg) {
                $count += OpcodeCache::invalidateApp($pkg);
            }
            $io->info(sprintf('OPcache invalidated for %d app file(s).', $count));
        }

        if ($input->getOption('opcache-reset')) {
            if ($this->confirm('Are you sure you want to perform a full OPcache reset?', $input, $output)) {
                $ok = OpcodeCache::reset();
                if ($ok) {
                    $io->success('OPcache reset successfully.');
                } else {
                    $io->warning('OPcache reset failed (not available or disabled).');
                }
            }
        }

        return Command::SUCCESS;
    }
}

