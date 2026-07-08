<?php

namespace Pinoox\Terminal\Pinx;

use Pinoox\Component\Package\Pinx\PinxBuildConfig;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Pinx;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'release',
    description: 'Bump app version, build .pinx, and optionally sign for distribution',
)]
class ReleaseCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Bump version-name and version-code in app.php, then build a .pinx package.',
                [
                    'release com_my_shop --bump=patch --yes',
                    'release com_my_shop --bump=minor --sign --yes',
                    'release com_my_shop --bump=2.0.0 --yes',
                ],
            ))
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('bump', 'b', InputOption::VALUE_REQUIRED, 'Version bump: patch, minor, major, or explicit version-name')
            ->addOption('sign', 's', InputOption::VALUE_NONE, 'Sign the release package')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'excludeSystem' => true,
            'sectionTitle' => 'Packages available for release',
        ]);

        $engine = AppEngine::___();
        $appFile = $engine->path($package, 'app.php');
        if (!is_file($appFile)) {
            $io->error('app.php was not found for ' . $package . '.');

            return Command::FAILURE;
        }

        if (!is_writable($appFile)) {
            $io->error('app.php is not writable: ' . $appFile);

            return Command::FAILURE;
        }

        $config = PinxBuildConfig::appConfigArray($engine, $package);
        $versionName = (string) ($config['version-name'] ?? '1.0.0');
        $versionCode = (int) ($config['version-code'] ?? 1);
        $bump = trim((string) ($input->getOption('bump') ?: 'patch'));

        [$newName, $newCode] = $this->bumpVersion($versionName, $versionCode, $bump);

        if (!$input->getOption('yes') && !$io->confirm(
            sprintf('Release %s %s (#%d → #%d)?', $package, $newName, $versionCode, $newCode),
            true,
        )) {
            $io->warning('Release canceled.');

            return Command::SUCCESS;
        }

        $this->writeAppVersions($appFile, $newName, $newCode);
        $io->writeln('Updated app.php → version-name=' . $newName . ', version-code=' . $newCode);

        $buildOptions = [];
        $signEnabled = !empty($config['pinx']['sign']['enabled']);
        if ($input->getOption('sign') || $signEnabled) {
            $buildOptions['sign'] = true;
        }

        $progress = new ProgressBar($output, 100);
        $progress->setFormat(' %percent:3s%% [%bar%] %message%');
        $progress->setMessage('Starting release build...');
        $progress->start();

        $buildOptions['progress'] = static function (string $phase, string $message, ?int $percent = null) use ($progress): void {
            if ($percent !== null) {
                $progress->setProgress($percent);
            }
            $progress->setMessage($message);
        };

        try {
            $result = Pinx::builder()->build($package, null, $buildOptions);
            $progress->finish();
            $output->writeln('');

            $manifest = $result['manifest'];
            $io->success('Release package built successfully.');
            $io->definitionList(
                ['Package' => $package],
                ['Version' => $manifest->versionName() . ' #' . $manifest->versionCode()],
                ['File' => $result['path']],
                ['Signed' => $result['signed'] ? 'yes' : 'no'],
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->clear();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function bumpVersion(string $name, int $code, string $bump): array
    {
        if (!in_array($bump, ['patch', 'minor', 'major'], true)) {
            return [$bump, $code + 1];
        }

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $name, $matches)) {
            return ['1.0.1', $code + 1];
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        if ($bump === 'major') {
            $major++;
            $minor = 0;
            $patch = 0;
        } elseif ($bump === 'minor') {
            $minor++;
            $patch = 0;
        } else {
            $patch++;
        }

        return [sprintf('%d.%d.%d', $major, $minor, $patch), $code + 1];
    }

    private function writeAppVersions(string $appFile, string $versionName, int $versionCode): void
    {
        $contents = file_get_contents($appFile);

        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read app.php');
        }

        $contents = preg_replace(
            "/(['\"]version-name['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/",
            '${1}' . $versionName . '${3}',
            $contents,
            1,
        ) ?? $contents;

        $contents = preg_replace(
            "/(['\"]version-code['\"]\s*=>\s*)\d+/",
            '${1}' . $versionCode,
            $contents,
            1,
        ) ?? $contents;

        file_put_contents($appFile, $contents);
    }
}
