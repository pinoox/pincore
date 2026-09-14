<?php

namespace Pinoox\Terminal\App;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Pinker;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:unlink',
    aliases: ['unlink'],
    description: 'Unlink and deregister an external Pinoox app from the project',
)]
class AppUnlinkCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Removes a linked external app symlink and its registration from apps.config.php and app-router.config.php.

Examples:
  php pinoox app:unlink com_pinoox_sms
  php pinoox app:unlink com_pinoox_sms --keep-routes
HELP
            )
            ->addArgument('package', InputArgument::REQUIRED, 'Package name to unlink')
            ->addOption('keep-routes', null, InputOption::VALUE_NONE, 'Keep URL route mappings in app-router.config.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        $package = (string) $input->getArgument('package');
        $projectRoot = rtrim(str_replace('\\', '/', SystemConfig::rootPath()), '/');

        $targetLink = $projectRoot . '/apps/' . $package;

        if (is_link($targetLink)) {
            @unlink($targetLink);
            $io->writeln(sprintf(' <info>✓</info> Removed symlink: %s', $targetLink));
        } elseif (is_dir($targetLink)) {
            $io->warning(sprintf('Target "%s" is a real directory (not a symlink). Skipping file deletion.', $targetLink));
        } else {
            $io->note(sprintf('No link found at: %s', $targetLink));
        }

        // Deregister from platform/apps.config.php or config/apps.config.php
        $registryFile = $projectRoot . '/platform/apps.config.php';
        if (!is_file($registryFile)) {
            $registryFile = $projectRoot . '/config/apps.config.php';
        }

        if (is_file($registryFile)) {
            $content = require $registryFile;
            if (is_array($content)) {
                $packages = $content['packages'] ?? $content['apps'] ?? [];
                if (isset($packages[$package])) {
                    unset($packages[$package]);
                    $content['packages'] = $packages;
                    file_put_contents($registryFile, "<?php\n\nreturn " . var_export($content, true) . ";\n");
                    $io->writeln(sprintf(' <info>✓</info> Deregistered from: %s', $registryFile));
                }
            }
        }

        // Remove route if not keep-routes
        if (!$input->getOption('keep-routes')) {
            $routerFile = $projectRoot . '/platform/app-router.config.php';
            if (!is_file($routerFile)) {
                $routerFile = $projectRoot . '/config/app-router.config.php';
            }
            if (is_file($routerFile)) {
                $routes = require $routerFile;
                if (is_array($routes)) {
                    $changed = false;
                    foreach ($routes as $route => $pkg) {
                        if ($pkg === $package) {
                            unset($routes[$route]);
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        file_put_contents($routerFile, "<?php\n\nreturn " . var_export($routes, true) . ";\n");
                        $io->writeln(sprintf(' <info>✓</info> Removed route mappings from: %s', $routerFile));
                    }
                }
            }
        }

        try {
            if (class_exists(Pinker::class)) {
                Pinker::clear();
            }
        } catch (\Throwable) {
        }

        $io->success(sprintf('App [%s] unlinked successfully.', $package));

        return Command::SUCCESS;
    }
}
