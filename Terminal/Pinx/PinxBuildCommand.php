<?php

namespace Pinoox\Terminal\Pinx;

use Pinoox\Component\Package\AppDependency;
use Pinoox\Component\Package\Pinx\PinxBuildConfig;
use Pinoox\Component\Package\Pinx\PinxCliManifest;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Package\Pinx\PinxVersion;
use Pinoox\Component\Package\Pinx\PlatformBuildConfig;
use Pinoox\Component\Terminal;
use Pinoox\Support\ProjectCli;
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
    name: 'pinx:build',
    description: 'Build a .pinx install package from an app/theme, or a .zip platform archive',
    aliases: ['pinx:b', 'build'],
)]

class PinxBuildCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Build a .pinx package using app.php build/pinx settings, or a .zip platform archive with "platform".',
                [
                    'pinx:build com_my_shop',
                    'pinx:build com_my_shop --sign',
                    'pinx:build platform',
                    'pinx:build platform --output=/tmp/pinoox.zip',
                    'pinx:build com_my_shop --yes',
                ],
            ))
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name, or "platform" for a platform .zip')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output .pinx or .zip file path')
            ->addOption('sign', 's', InputOption::VALUE_NONE, 'Sign the package (auto when key exists or app.php pinx.sign.enabled)')
            ->addOption('no-sign', null, InputOption::VALUE_NONE, 'Build without signing even when a key exists')
            ->addOption('sign-key', null, InputOption::VALUE_REQUIRED, 'Path to sign.key.json')
            ->addOption('key-id', null, InputOption::VALUE_REQUIRED, 'Publisher key id stored in signature.json')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale for resolved title/description (e.g. en, fa)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $requested = $this->readPackageInput($input, 'package', ['package', 'app']);

        if ($requested === 'platform') {
            return $this->executePlatformBuild($input, $output, $io);
        }

        $package = $this->resolvePackageRequired($input, $output, $io, [
            'excludeSystem' => true,
            'appsOnly' => true,
            'sectionTitle' => 'Packages available for pinx build',
        ]);

        $builder = Pinx::builder();
        $outputPath = $input->getOption('output');

        $locale = trim((string) $input->getOption('locale'));
        $localeArg = $locale !== '' ? $locale : null;

        if (!$input->getOption('yes')) {
            $io->section('Pinx build');
            $build = PinxBuildConfig::resolve(AppEngine::___(), $package);
            $previewManifest = PinxManifest::fromAppConfig(
                PinxBuildConfig::appConfigArray(AppEngine::___(), $package),
                (string) ($build['type'] ?? PinxManifest::TYPE_APP),
                $build,
            );
            $previewManifest->validate();
            $io->text([
                'Package: <info>' . $package . '</info>',
                'Name: <info>' . $previewManifest->title($localeArg) . '</info>',
                'Description: <info>' . ($previewManifest->description($localeArg) ?: '—') . '</info>',
                'Output: <info>' . ($outputPath ?: '(auto in ~pinx/export/{package}/)') . '</info>',
            ]);

            if (!$io->confirm('Proceed with build?', false)) {
                $io->warning('Build canceled.');
                return Command::SUCCESS;
            }
        }

        $progress = new ProgressBar($output, 100);
        $progress->setFormat(' %percent:3s%% [%bar%] %message%');
        $progress->setMessage('Starting build...');
        $progress->start();

        $buildOptions = [];
        if ($input->getOption('no-sign')) {
            $buildOptions['sign'] = false;
        } elseif ($input->getOption('sign')) {
            $buildOptions['sign'] = true;
        }
        if ($input->getOption('sign-key')) {
            $buildOptions['sign_key'] = (string) $input->getOption('sign-key');
        }
        if ($input->getOption('key-id')) {
            $buildOptions['key_id'] = (string) $input->getOption('key-id');
        }
        $buildOptions['progress'] = static function (string $phase, string $message, ?int $percent = null) use ($progress): void {
            if ($percent !== null) {
                $progress->setProgress($percent);
            }
            $progress->setMessage($message);
        };

        try {
            $result = $builder->build($package, $outputPath, $buildOptions);
            $progress->finish();
            $output->writeln('');

            $manifest = $result['manifest'];
            $io->success('Pinx package created successfully.');
            $rows = [
                ['File', $result['path']],
                ['Type', $manifest->type()],
                ['Package', $manifest->package()],
                ...PinxCliManifest::summaryRows($manifest, $localeArg),
                ['Version', $manifest->versionName() . ' #' . $manifest->versionCode()],
                ['Files', (string) $result['files']],
                ['Signed', $result['signed'] ? 'yes' : 'no'],
            ];

            if ($result['signed'] && is_array($result['signature'])) {
                $rows[] = ['Key ID', (string) ($result['signature']['key_id'] ?? '')];
                $rows[] = ['Fingerprint', (string) ($result['signature']['fingerprint'] ?? '')];
            }

            $depends = AppDependency::inspect(
                AppDependency::fromAppConfig(PinxBuildConfig::appConfigArray(AppEngine::___(), $package)),
                AppEngine::___(),
            );
            if ($depends !== []) {
                $dependsSummary = implode(', ', array_map(
                    static fn (array $row): string => $row['package']
                        . ($row['optional'] ? ' (optional)' : '')
                        . ($row['min_code'] !== null ? ' >=' . $row['min_code'] : ''),
                    $depends,
                ));
                $rows[] = ['Depends', $dependsSummary];
            }

            if (!empty($result['composer'])) {
                $packages = is_array($result['composer_packages'] ?? null) ? $result['composer_packages'] : [];
                $rows[] = [
                    'Composer',
                    $packages === []
                        ? 'vendor/ bundled (app requires)'
                        : 'vendor/ bundled: ' . implode(', ', $packages),
                ];
            }

            $io->definitionList(...array_map(static fn (array $row) => [$row[0] => $row[1]], $rows));

            if ($manifest->isTheme()) {
                $io->note('Theme package for app ' . $manifest->targetApp() . ', theme ' . $manifest->themeName());
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->clear();
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function executePlatformBuild(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $outputPath = $input->getOption('output');
        $build = PlatformBuildConfig::resolve();
        $version = PinxVersion::platform();

        if (!$input->getOption('yes')) {
            $io->section('Platform build');
            $io->text([
                'Target: <info>platform distribution (.zip)</info>',
                'Version: <info>' . ($version['name'] !== '' ? $version['name'] : 'unknown')
                    . ($version['code'] !== null ? ' #' . $version['code'] : '') . '</info>',
                'Config: <info>' . PlatformBuildConfig::configFile() . '</info>',
                'Output: <info>' . ($outputPath ?: '(auto in ~/pinx/export/platform/)') . '</info>',
            ]);

            if (!$io->confirm('Proceed with platform build?', false)) {
                $io->warning('Build canceled.');

                return Command::SUCCESS;
            }
        }

        $progress = new ProgressBar($output, 100);
        $progress->setFormat(' %percent:3s%% [%bar%] %message%');
        $progress->setMessage('Starting platform build...');
        $progress->start();

        $buildOptions = [
            'progress' => static function (string $phase, string $message, ?int $percent = null) use ($progress): void {
                if ($percent !== null) {
                    $progress->setProgress($percent);
                }
                $progress->setMessage($message);
            },
        ];

        try {
            $result = Pinx::platformBuilder()->build(
                is_string($outputPath) && $outputPath !== '' ? $outputPath : null,
                $buildOptions,
            );
            $progress->finish();
            $output->writeln('');

            $io->success('Platform archive created successfully.');
            $rows = [
                ['File', $result['path']],
                ['Format', '.zip'],
                ['Version', ($result['version_name'] !== '' ? $result['version_name'] : 'unknown')
                    . ($result['version_code'] !== null ? ' #' . $result['version_code'] : '')],
                ['Files', (string) $result['files']],
            ];

            if (!empty($result['composer'])) {
                $packages = is_array($result['composer_packages'] ?? null) ? $result['composer_packages'] : [];
                $rows[] = [
                    'Composer',
                    $packages === []
                        ? 'vendor/ bundled (production, no dev)'
                        : 'vendor/ bundled: ' . implode(', ', $packages),
                ];
            } else {
                $rows[] = ['Composer', 'skipped'];
            }

            $appComposers = is_array($result['app_composers'] ?? null) ? $result['app_composers'] : [];

            if ($appComposers !== []) {
                $rows[] = ['App vendors', implode(', ', $appComposers)];
            }

            $materialized = is_array($result['materialized_packages'] ?? null) ? $result['materialized_packages'] : [];

            if ($materialized !== []) {
                $rows[] = ['Path packages', implode(', ', $materialized)];
            }

            if ($build['exclude_theme_src']) {
                $rows[] = ['Theme src', 'excluded'];
            }

            if ($build['gitignore']) {
                $rows[] = ['Gitignore', 'applied'];
            }

            $io->definitionList(...array_map(static fn (array $row) => [$row[0] => $row[1]], $rows));
            $io->note('Platform builds use platform/build.config.php. App packages still ship as signed .pinx files.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->clear();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}

