<?php

namespace Pinoox\Terminal\Pinx;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Package\Pinx\PinxPaths;
use Pinoox\Component\Package\Pinx\PinxVersion;
use Pinoox\Component\Package\Pinx\PlatformArchive;
use Pinoox\Component\Terminal;
use Pinoox\Portal\Pinx;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinx:update',
    description: 'Update the platform from a built .zip archive (extract, migrate, patch)',
    aliases: ['update'],
)]
class PinxUpdateCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Apply a platform .zip from "php pinoox build platform": overwrite project files, keep runtime data, then run core and bundled-app migrations/patches like the web installer.',
                [
                    'pinx:update pinoox_3_3_14_v55.zip',
                    'pinx:update platform',
                    'pinx:update platform /tmp/pinoox.zip',
                    'pinx:update archive.zip --dry-run',
                    'pinx:update archive.zip --force --yes',
                ],
                'Preserves .env, pinker/ (bake, state, stable), storage/, uploads/, downloads/, pinroll/, and apps that are not in the zip.',
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'Archive path, or "platform" (same as pinx:build platform)')
            ->addArgument('archive', InputArgument::OPTIONAL, 'Platform .zip path when target is "platform"')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow applying an older archive than the installed platform')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and list steps without extracting')
            ->addOption('skip-migrate', null, InputOption::VALUE_NONE, 'Skip database migrations')
            ->addOption('skip-patch', null, InputOption::VALUE_NONE, 'Skip data patches')
            ->addOption('skip-lifecycle', null, InputOption::VALUE_NONE, 'Skip lifecycle.php update hooks')
            ->addOption('skip-cache', null, InputOption::VALUE_NONE, 'Skip cache rebuild');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $archivePath = $this->resolveArchivePath(
            (string) $input->getArgument('target'),
            (string) $input->getArgument('archive'),
        );

        if ($archivePath === null || !is_file($archivePath)) {
            $io->error('Platform archive not found. Pass a .zip path or run: php pinoox build platform');

            return Command::FAILURE;
        }

        try {
            $manifest = PlatformArchive::readManifest($archivePath);
            $apps = PlatformArchive::listApps($archivePath);
            $toVersion = PlatformArchive::versionFromManifest($manifest);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $fromVersion = PinxVersion::platform();
        $dryRun = (bool) $input->getOption('dry-run');

        $io->section($dryRun ? 'Platform update (dry run)' : 'Platform update');
        $io->definitionList(
            ['File' => $archivePath],
            ['Installed' => $this->formatVersion($fromVersion)],
            ['Archive' => $this->formatVersion($toVersion)],
            ['Apps' => $apps === [] ? '—' : implode(', ', $apps)],
            ['Preserved' => implode(', ', ['.env', 'pinker/bake', 'pinker/state', 'pinker/stable', 'storage/', 'uploads/', 'downloads/', 'pinroll/', 'platform routes'])],
        );

        if (!$input->getOption('yes') && !$dryRun && !$io->confirm('Apply this platform archive?', true)) {
            $io->warning('Update canceled.');

            return Command::SUCCESS;
        }

        $updater = Pinx::platformUpdater();
        $updater->onStep(static function (string $step, string $status, string $message) use ($io): void {
            $io->writeln(sprintf('  <comment>[%s]</comment> %s: %s', strtoupper($status), $step, $message));
        });

        $result = $updater->update($archivePath, [
            'force' => (bool) $input->getOption('force'),
            'dry_run' => $dryRun,
            'skip_migrate' => (bool) $input->getOption('skip-migrate'),
            'skip_patch' => (bool) $input->getOption('skip-patch'),
            'skip_lifecycle' => (bool) $input->getOption('skip-lifecycle'),
            'skip_cache' => (bool) $input->getOption('skip-cache'),
        ]);

        if (!$result->success) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success($result->message);

        return Command::SUCCESS;
    }

    private function resolveArchivePath(string $target, string $archive): ?string
    {
        $target = trim(str_replace('\\', '/', $target));
        $archive = trim(str_replace('\\', '/', $archive));

        if ($target === 'platform' || $target === '') {
            return $this->existingFile($archive) ?? PinxPaths::latestPlatformArchive();
        }

        return $this->existingFile($target) ?? $this->existingFile($archive);
    }

    private function existingFile(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $candidates = [$path];
        $base = rtrim(str_replace('\\', '/', (string) Loader::getBasePath()), '/');

        if (!preg_match('/^[A-Za-z]:\//', $path) && !str_starts_with($path, '/')) {
            $candidates[] = $base . '/' . ltrim($path, '/');
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array{name: string, code: ?int} $version
     */
    private function formatVersion(array $version): string
    {
        $name = $version['name'] !== '' ? $version['name'] : 'unknown';

        return $version['code'] !== null ? $name . ' #' . $version['code'] : $name;
    }
}
