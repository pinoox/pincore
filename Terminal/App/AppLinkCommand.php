<?php

namespace Pinoox\Terminal\App;

use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppRouter;
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
    name: 'app:link',
    aliases: ['link'],
    description: 'Symlink and register an external Pinoox app for zero-config local development',
)]
class AppLinkCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Links an external Pinoox app directory into the current project's apps/ folder via symbolic link.
Enables instant cross-app calling (HMVC / App::meeting / Portal), PSR-4 autoloading, and routing without reinstallation.

Examples:
  php pinoox app:link /path/to/platform/apps/com_pinoox_sms
  php pinoox app:link ../platform/apps/com_pinoox_sms --route=/sms
  php pinoox app:link ../external/my_app com_custom_name --force
HELP
            )
            ->addArgument('source', InputArgument::REQUIRED, 'Source directory path of the external app')
            ->addArgument('package', InputArgument::OPTIONAL, 'Package name override (defaults to package in app.php)')
            ->addOption('route', 'r', InputOption::VALUE_REQUIRED, 'Optional URL route prefix to mount in app-router.config.php')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite symlink if it already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        $sourceRaw = (string) $input->getArgument('source');
        $projectRoot = rtrim(str_replace('\\', '/', SystemConfig::rootPath()), '/');

        // Resolve source path relative or absolute
        $sourcePath = $sourceRaw;
        if (!str_starts_with($sourcePath, '/') && !preg_match('/^[A-Za-z]:\//', $sourcePath)) {
            $sourcePath = $projectRoot . '/' . $sourcePath;
        }

        $realSource = realpath($sourcePath);
        if ($realSource === false || !is_dir($realSource)) {
            $io->error(sprintf('Source directory does not exist: %s', $sourceRaw));
            return Command::FAILURE;
        }

        $appFile = $realSource . '/app.php';
        if (!is_file($appFile)) {
            $io->error(sprintf('Directory is not a valid Pinoox app (missing app.php): %s', $realSource));
            return Command::FAILURE;
        }

        // Read app package name
        $appData = include $appFile;
        $discoveredPackage = is_array($appData) ? ($appData['package'] ?? null) : null;
        $package = (string) ($input->getArgument('package') ?: $discoveredPackage ?: basename($realSource));

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $package)) {
            $io->error(sprintf('Invalid package name: %s', $package));
            return Command::FAILURE;
        }

        // Ensure apps directory exists
        $appsDir = $projectRoot . '/apps';
        if (!is_dir($appsDir)) {
            if (!mkdir($appsDir, 0755, true) && !is_dir($appsDir)) {
                $io->error(sprintf('Failed to create apps directory: %s', $appsDir));
                return Command::FAILURE;
            }
        }

        $targetLink = $appsDir . '/' . $package;

        // Check existing target
        if (is_link($targetLink) || file_exists($targetLink)) {
            $currentTarget = is_link($targetLink) ? readlink($targetLink) : null;
            if ($currentTarget === $realSource) {
                $io->note(sprintf('Symlink already exists and points to source: %s', $targetLink));
            } elseif ($input->getOption('force')) {
                @unlink($targetLink);
                if (!symlink($realSource, $targetLink)) {
                    $io->error(sprintf('Failed to recreate symlink at: %s', $targetLink));
                    return Command::FAILURE;
                }
            } else {
                $io->error(sprintf('Target path already exists (%s). Use --force to overwrite.', $targetLink));
                return Command::FAILURE;
            }
        } else {
            if (!symlink($realSource, $targetLink)) {
                $io->error(sprintf('Failed to create symlink from "%s" to "%s"', $realSource, $targetLink));
                return Command::FAILURE;
            }
        }

        // Register in platform/apps.config.php if project deploy layer exists
        $registryFile = $projectRoot . '/platform/apps.config.php';
        if (!is_file($registryFile)) {
            $registryFile = $projectRoot . '/config/apps.config.php';
        }

        if (is_file($registryFile)) {
            $this->registerInAppsConfig($registryFile, $package, $realSource);
        }

        // Register route if provided
        $route = $input->getOption('route');
        if (is_string($route) && $route !== '') {
            $route = '/' . ltrim($route, '/');
            $routerFile = $projectRoot . '/platform/app-router.config.php';
            if (!is_file($routerFile)) {
                $routerFile = $projectRoot . '/config/app-router.config.php';
            }
            if (is_file($routerFile)) {
                $this->registerInRouterConfig($routerFile, $route, $package);
            }
        }

        // Clear Pinker cache if available
        try {
            if (class_exists(Pinker::class)) {
                Pinker::clear();
            }
        } catch (\Throwable) {
        }

        $io->success(sprintf('App [%s] linked successfully!', $package));

        $io->table(
            ['Property', 'Value'],
            [
                ['Package', $package],
                ['Source Path', $realSource],
                ['Symlink', $targetLink],
                ['Route', $route ?? '(none)'],
                ['Autoloading', 'App\\' . $package . '\\ (PSR-4 active via AppEngine)'],
            ]
        );

        // Database isolation tip
        $appEnvFile = $realSource . '/.env';
        if (!is_file($appEnvFile)) {
            $io->section('Database Note:');
            $io->writeln([
                ' <info>💡 Tip:</info> If this app maintains its own separate database (e.g. from Platform),',
                sprintf('    create an <comment>.env</comment> inside <comment>%s</comment> with:', $realSource),
                '      <comment>DB_DRIVER=mysql</comment>',
                '      <comment>DB_DATABASE=your_platform_db</comment>',
                '      <comment>DB_PREFIX=sms_</comment>',
                '    Pinoox will automatically isolate its queries to that database without altering the host project database.',
            ]);
        }

        return Command::SUCCESS;
    }

    private function registerInAppsConfig(string $file, string $package, string $sourcePath): void
    {
        $content = require $file;
        if (!is_array($content)) {
            return;
        }

        $packages = $content['packages'] ?? $content['apps'] ?? [];
        if (!is_array($packages)) {
            $packages = [];
        }

        $packages[$package] = $sourcePath;
        $content['packages'] = $packages;

        $exported = var_export($content, true);
        file_put_contents($file, "<?php\n\nreturn " . $exported . ";\n");
    }

    private function registerInRouterConfig(string $file, string $route, string $package): void
    {
        $routes = require $file;
        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[$route] = $package;
        $exported = var_export($routes, true);
        file_put_contents($file, "<?php\n\nreturn " . $exported . ";\n");
    }
}
